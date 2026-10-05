<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Shell;
use App\Voice\VoiceSession;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\Channel;
use Discord\Voice\Client;
use Discord\Voice\Dave\State;
use Discord\Voice\Gateway\WS;
use Discord\Voice\Ogg\Buffer;
use Discord\Voice\Ogg\OggStream;
use Discord\Voice\Processes\Ffmpeg;
use Discord\Voice\Processes\OpusFfi;
use Discord\Voice\Rtp\EncryptionMode;
use Discord\Voice\Rtp\Packet;
use Discord\Voice\Rtp\UDP;
use Discord\Voice\Speaking;
use Discord\Voice\VoiceClient;
use Ratchet\Client\WebSocket;
use React\Datagram\Socket;
use React\EventLoop\Loop;
use ReflectionClass;

use function React\Async\await;

/**
 * Runs a call through the real audio path, with only Discord's servers faked.
 *
 * Alice's question arrives as Opus audio, encrypted like Discord encrypts it, over UDP. It is
 * decrypted, decoded into her WAV recording and transcribed. Claude's answer is then encoded by
 * ffmpeg, encrypted and sent back over UDP. A local UDP socket stands in for Discord's media
 * server. whisper.cpp, Claude Code and Piper are still the scripts in tests/Fixtures, but Piper's
 * stand-in writes a real tone, in the same format as Piper's voices.
 *
 * Needs what the bot itself needs for voice: ffmpeg, libopus, and PHP's FFI and sodium extensions.
 */
final class VoiceCallTest extends VoiceTestCase
{
    private const int BOT_SSRC = 1;

    private const int ALICE_SSRC = 2;

    private const EncryptionMode MODE = EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE;

    private string $secretKey;

    /** Stands in for Discord's media server. */
    private Socket $mediaServer;

    private UDP $udp;

    /** @var list<string> Packets the bot sent to the media server. */
    private array $sentPackets = [];

    /** @var list<array<string, mixed>> Payloads the bot sent to the voice gateway. */
    private array $gatewayPayloads = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Also tells the voice library where ffmpeg is, like its voice client does when created.
        if (! Ffmpeg::checkForFFmpeg() || ! OpusFfi::isAvailable()) {
            $this->markTestSkipped('Needs ffmpeg and libopus, with PHP\'s FFI extension.');
        }

        $fixtures = dirname(__DIR__) . '/Fixtures';
        $this->setEnv(['PIPER_BINARY' => "{$fixtures}/fake-piper-tone", 'FFMPEG_BINARY' => 'ffmpeg']);
        $this->secretKey = random_bytes(32);

        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        $this->mediaServer = new Socket(Loop::get(), $server);
        $this->mediaServer->on('message', function (string $packet) {
            $this->sentPackets[] = $packet;
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->udp)) {
            $this->udp->close();
        }

        if (isset($this->mediaServer)) {
            $this->mediaServer->close();
        }
    }

    public function testRecordsAndAnswersAQuestionAskedOverTheNetwork(): void
    {
        $session = VoiceSession::start($vc = $this->connectedVoiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->announceSpeaker($vc, self::ALICE_SSRC, '555');
        $this->sendAudio($this->opusFrames(440), from: self::ALICE_SSRC, to: $this->udp->getLocalAddress());
        $this->waitUntil(
            fn () => in_array(VoiceClient::NOT_SPEAKING, array_column(array_column($this->gatewayPayloads, 'd'), 'speaking'), true),
            'the answer to finish playing',
            timeout: 20.0,
        );

        // Alice's question was decrypted and decoded into her recording: a second of her 440 Hz tone.
        await($session->stop());
        $recording = $this->pcm(file_get_contents("{$session->directory}/555-1.wav"), wavHeader: true);
        $this->assertEqualsWithDelta(1.0, $this->seconds($recording), 0.05, 'Length of the recording.');
        $this->assertEqualsWithDelta(440, $this->frequency($recording), 20, 'Pitch of the recording.');

        // She was understood and answered, and the call was summarized when it ended. Claude's stand-in gives both the same text.
        $this->assertStringContainsString('] Alice: Hey Claude, what time is it?', $this->transcript($session));
        $this->assertStringContainsString('] Claude: It is a quarter past four.', $this->transcript($session));
        $this->assertSame(["> **Alice:** Hey Claude, what time is it?\nIt is a quarter past four.", 'It is a quarter past four.'], $this->sent);
        $this->assertFileExists("{$session->directory}/claude-2.ogg");

        // The bot said it was speaking, then sent the answer: a second of Piper's 660 Hz tone.
        $this->assertSame(
            [['speaking' => VoiceClient::MICROPHONE, 'delay' => 0, 'ssrc' => self::BOT_SSRC], ['speaking' => VoiceClient::NOT_SPEAKING, 'delay' => 0, 'ssrc' => self::BOT_SSRC]],
            array_column($this->gatewayPayloads, 'd'),
        );
        $answer = $this->decodeSentAudio();
        $this->assertEqualsWithDelta(1.0, $this->seconds($answer), 0.05, 'Length of the answer.');
        $this->assertEqualsWithDelta(660, $this->frequency($answer), 20, 'Pitch of the answer.');

        $this->assertSame([], $this->loggedProblems());
    }

    public function testSpeaksEachSentenceOfAnAnswerOverTheNetwork(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past four. ', 'Time for a cup of tea.')]);
        $session = VoiceSession::start($vc = $this->connectedVoiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->announceSpeaker($vc, self::ALICE_SSRC, '555');
        $this->sendAudio($this->opusFrames(440), from: self::ALICE_SSRC, to: $this->udp->getLocalAddress());
        $this->waitUntil(
            fn () => count(array_keys(array_column(array_column($this->gatewayPayloads, 'd'), 'speaking'), VoiceClient::NOT_SPEAKING, true)) === 2,
            'both sentences to finish playing',
            timeout: 30.0,
        );
        await($session->stop());

        // The real voice client was given the second sentence once it had finished the first one, and played both.
        $this->assertSame(
            [VoiceClient::MICROPHONE, VoiceClient::NOT_SPEAKING, VoiceClient::MICROPHONE, VoiceClient::NOT_SPEAKING],
            array_column(array_column($this->gatewayPayloads, 'd'), 'speaking'),
        );
        $this->assertFileExists("{$session->directory}/claude-2.ogg");
        $this->assertFileExists("{$session->directory}/claude-3.ogg");

        // Piper's stand-in makes a second of its 660 Hz tone for each sentence.
        $answer = $this->decodeSentAudio();
        $this->assertEqualsWithDelta(2.0, $this->seconds($answer), 0.1, 'Length of the answer.');
        $this->assertEqualsWithDelta(660, $this->frequency($answer), 20, 'Pitch of the answer.');

        $this->assertSame([], $this->loggedProblems());
    }

    /**
     * A voice client connected to the stand-in media server. Its voice gateway connection is faked:
     * what it sends there is collected in {@see $gatewayPayloads}.
     */
    private function connectedVoiceClient(Channel $channel): Client
    {
        // The ffmpeg process it starts for each speaker only feeds 'channel-opus' events and leaves a
        // file in the temp directory; the WAV recording doesn't use it.
        $vc = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['createDecoder', 'close'])->getMock();
        $vc->expects($this->once())->method('close');
        $vc->method('createDecoder')->willReturnCallback(function (object $ss) use ($vc): void {
            $vc->voiceDecoders[$ss->ssrc] = $this->decoderProcess();
        });

        $vc->discord = $this->discord;
        $vc->channel = $channel;
        $vc->ready = true;
        $vc->deaf = false;
        $vc->ssrc = self::BOT_SSRC;
        $vc->voiceDecoders = [];
        $vc->receiveStreams = [];
        $this->setProperty($vc, VoiceClient::class, 'speakingStatus', Collection::for(Speaking::class, 'ssrc'));
        $this->setProperty($vc, VoiceClient::class, 'ssrcToUserId', []);
        $this->setProperty($vc, VoiceClient::class, 'readOpusTimer', null);
        $this->setProperty($vc, VoiceClient::class, 'startTime', 0);

        // The voice gateway connection, as it is after Discord sent the session's key. End-to-end
        // encryption is off: DAVE needs Discord's servers to set up.
        $ws = (new ReflectionClass(WS::class))->newInstanceWithoutConstructor();
        $ws->vc = $vc;
        $ws->mode = self::MODE->value;
        $this->setProperty($ws, WS::class, 'secretKey', $this->secretKey);
        $this->setProperty($ws, WS::class, 'daveState', new State());
        $socket = static::getStubBuilder(WebSocket::class)->disableOriginalConstructor()->onlyMethods(['send'])->getStub();
        $socket->method('send')->willReturnCallback(function (string $payload): void {
            $this->gatewayPayloads[] = json_decode($payload, true);
        });
        $this->setProperty($ws, WS::class, 'socket', $socket);

        $this->udp = new UDP(Loop::get(), stream_socket_client('udp://' . $this->mediaServer->getLocalAddress()), ws: $ws);
        $this->udp->handleMessages($this->secretKey);
        $vc->udp = $this->udp;

        return $vc;
    }

    /**
     * A second of a tone, encoded by ffmpeg into 20 ms Opus frames.
     *
     * @return list<string>
     */
    private function opusFrames(int $frequency): array
    {
        $ogg = await(Shell::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', "sine=frequency={$frequency}:duration=1", '-c:a', 'libopus', '-f', 'ogg', 'pipe:1']));
        $buffer = new Buffer();
        $buffer->write($ogg);
        $buffer->end();
        $stream = await(OggStream::fromBuffer($buffer));

        $frames = [];
        while (($frame = await($stream->getPacket())) !== null) {
            $frames[] = $frame;
        }

        return $frames;
    }

    /**
     * Sends Opus frames from the media server like Discord does: encrypted, one every 20 ms.
     *
     * @param list<string> $frames
     */
    private function sendAudio(array $frames, int $from, string $to): void
    {
        foreach ($frames as $i => $frame) {
            Loop::addTimer($i * 0.02, function () use ($frame, $i, $from, $to) {
                $packet = new Packet($frame, $from, $i, $i * 960, false, $this->secretKey, nonce: $i, mode: self::MODE);
                $this->mediaServer->send($packet->getEncryptedMessage(), $to);
            });
        }
    }

    /**
     * Decrypts and decodes the audio the bot sent to the media server.
     *
     * @return list<int> Left-channel samples.
     */
    private function decodeSentAudio(): array
    {
        $decoder = new OpusFfi();
        $pcm = '';

        foreach ($this->sentPackets as $message) {
            $packet = new Packet($message, key: $this->secretKey, mode: self::MODE);
            $this->assertSame(self::BOT_SSRC, $packet->getSSRC());

            // Silence frames are only padding between sentences.
            if ($packet->decryptedAudio !== "\xF8\xFF\xFE") {
                $pcm .= $decoder->decode($packet->decryptedAudio);
            }
        }

        return $this->pcm($pcm, wavHeader: false);
    }

    /**
     * @return list<int> Left-channel samples of 48 kHz 16-bit stereo audio.
     */
    private function pcm(string $audio, bool $wavHeader): array
    {
        $samples = array_values(unpack('s*', $wavHeader ? substr($audio, 44) : $audio));

        return array_values(array_filter($samples, fn (int $i) => $i % 2 === 0, ARRAY_FILTER_USE_KEY));
    }

    /**
     * @param list<int> $samples
     */
    private function seconds(array $samples): float
    {
        return count($samples) / 48000;
    }

    /**
     * The pitch of a tone, from how often it crosses zero.
     *
     * @param list<int> $samples
     */
    private function frequency(array $samples): float
    {
        $crossings = 0;

        for ($i = 1; $i < count($samples); $i++) {
            if (($samples[$i - 1] < 0) !== ($samples[$i] < 0)) {
                $crossings++;
            }
        }

        return $crossings / 2 / $this->seconds($samples);
    }
}
