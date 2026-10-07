<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Privacy\OptOuts;
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

    /** Unlikely to be the SSRC of anything else that left files in the temp folder. */
    private const int BOB_SSRC = 666002;

    private const EncryptionMode MODE = EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE;

    private string $secretKey;

    /** Stands in for Discord's media server. */
    private Socket $mediaServer;

    private UDP $udp;

    /** @var list<string> Packets the bot sent to the media server. */
    private array $sentPackets = [];

    /** @var list<float> When each of them arrived, by the clock that only goes forward: the wall clock steps on some machines. */
    private array $packetTimes = [];

    /** When the first of them arrived, by the wall clock, which is what the log's times are in. */
    private ?float $firstPacketAt = null;

    /** @var list<array<string, mixed>> Payloads the bot sent to the voice gateway. */
    private array $gatewayPayloads = [];

    /** @var list<float> When each of them was sent, by the same clock as {@see $packetTimes}. */
    private array $payloadTimes = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Also tells the voice library where ffmpeg is, like its voice client does when created.
        if (! Ffmpeg::checkForFFmpeg() || ! OpusFfi::isAvailable()) {
            $this->markTestSkipped('Needs ffmpeg and libopus, with PHP\'s FFI extension.');
        }

        $fixtures = dirname(__DIR__) . '/Fixtures';
        // Real Ogg Opus files, sent by the bot's own player, as in a call.
        $this->setEnv(['PIPER_BINARY' => "{$fixtures}/fake-piper-tone", 'FFMPEG_BINARY' => 'ffmpeg', 'VOICE_PLAYER' => 'bot']);
        $this->secretKey = random_bytes(32);

        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        $this->mediaServer = new Socket(Loop::get(), $server);
        $this->mediaServer->on('message', function (string $packet) {
            $this->sentPackets[] = $packet;
            $this->packetTimes[] = hrtime(true) / 1e9;
            $this->firstPacketAt ??= microtime(true);
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

        // The first packet followed the word that the bot speaks after the head start, not after the half second the
        // voice library waits: a generous bound, as the machine running the tests may be busy.
        $this->assertGreaterThanOrEqual(0.03, $this->packetTimes[0] - $this->payloadTimes[0], 'From the speaking flag to the first packet.');
        $this->assertLessThan(0.3, $this->packetTimes[0] - $this->payloadTimes[0], 'From the speaking flag to the first packet.');
        // "Started speaking" was logged when that packet went out, not the head start earlier, when the file was handed over.
        $started = array_values(array_filter($this->logs->getRecords(), fn ($record) => $record->message === 'Started speaking'));
        $this->assertCount(1, $started);
        $this->assertEqualsWithDelta($this->firstPacketAt, (float) $started[0]->datetime->format('U.u'), 0.02);

        $this->assertSame([], $this->loggedProblems());
    }

    public function testSpeaksEachSentenceOfAnAnswerOverTheNetwork(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past four. ', 'Time for a cup of tea.')]);
        $session = VoiceSession::start($vc = $this->connectedVoiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->announceSpeaker($vc, self::ALICE_SSRC, '555');
        $this->sendAudio($this->opusFrames(440), from: self::ALICE_SSRC, to: $this->udp->getLocalAddress());
        $this->waitUntil(
            fn () => in_array(VoiceClient::NOT_SPEAKING, array_column(array_column($this->gatewayPayloads, 'd'), 'speaking'), true),
            'the answer to finish playing',
            timeout: 30.0,
        );
        await($session->stop());

        // The second sentence was ready while the first was spoken, and followed it in the same stream: the bot said
        // it was speaking once, and both sentences were sent, without a gap between them.
        $this->assertSame(
            [VoiceClient::MICROPHONE, VoiceClient::NOT_SPEAKING],
            array_column(array_column($this->gatewayPayloads, 'd'), 'speaking'),
        );
        $this->assertFileExists("{$session->directory}/claude-2.ogg");
        $this->assertFileExists("{$session->directory}/claude-3.ogg");

        // Piper's stand-in makes a second of its 660 Hz tone for each sentence.
        $answer = $this->decodeSentAudio();
        $this->assertEqualsWithDelta(2.0, $this->seconds($answer), 0.1, 'Length of the answer.');
        $this->assertEqualsWithDelta(660, $this->frequency($answer), 20, 'Pitch of the answer.');
        // The packets came 20 ms apart, the second sentence's right after the first one's: no half second between them.
        $gaps = array_map(fn (int $i) => $this->packetTimes[$i] - $this->packetTimes[$i - 1], range(1, count($this->packetTimes) - 1));
        $this->assertLessThan(0.3, max($gaps), 'The longest gap between two packets.');
        // And each held 20 ms: two seconds of them, the encoder's priming, and five frames of silence. The packets go
        // to Discord as ffmpeg wrote them, so a file of longer frames would be sent too fast, and sound like it.
        $this->assertEqualsWithDelta(107, count($this->sentPackets), 4, 'Packets for two seconds of 20 ms frames.');

        $this->assertSame([], $this->loggedProblems());
    }

    public function testStopsSendingWhenTheCallEndsInTheMiddleOfASentence(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past four. ', 'Time for a cup of tea.')]);
        $session = VoiceSession::start($vc = $this->connectedVoiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->announceSpeaker($vc, self::ALICE_SSRC, '555');
        $this->sendAudio($this->opusFrames(440), from: self::ALICE_SSRC, to: $this->udp->getLocalAddress());
        $this->waitUntil(fn () => count($this->sentPackets) >= 5, 'the bot to start speaking', timeout: 20.0);

        // The call stops in the middle of the first sentence, as with /stop: the player is stopped before the
        // voice client is closed, and the second sentence is never given to it.
        await($session->stop());
        await($this->after(0.1));
        $sent = count($this->sentPackets);
        await($this->after(0.3));

        $this->assertSame($sent, count($this->sentPackets), 'Nothing more was sent once the call had stopped.');
        $this->assertSame([VoiceClient::MICROPHONE, VoiceClient::NOT_SPEAKING], array_column(array_column($this->gatewayPayloads, 'd'), 'speaking'));
        $this->assertLessThan(0.95, $this->seconds($this->decodeSentAudio()), 'It did not finish its first sentence.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testStopsSpeakingOverTheNetworkWhenTheAnswerIsTalkedOver(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past four. ', 'Time for a cup of tea.')]);
        $session = VoiceSession::start($vc = $this->connectedVoiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $frames = $this->opusFrames(440);

        $this->announceSpeaker($vc, self::ALICE_SSRC, '555');
        $this->sendAudio($frames, from: self::ALICE_SSRC, to: $this->udp->getLocalAddress());
        $this->waitUntil(fn () => count($this->sentPackets) >= 5, 'the bot to start speaking', timeout: 20.0);

        // The bot has just started on its first sentence, a second of tone, when Alice laughs for a second, which
        // whisper makes no words of.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => '(laughs)']);
        $this->sendAudio($frames, from: self::ALICE_SSRC, to: $this->udp->getLocalAddress(), offset: count($frames));
        $this->waitUntil(fn () => count($this->logged('Transcribed')) === 2, 'what Alice said over the answer to be transcribed', timeout: 20.0);
        await($session->stop());

        // The real voice client was stopped in the middle of the sentence, and never given the second one.
        $this->assertCount(1, $this->logged('Interrupted'));
        $this->assertSame(
            [VoiceClient::MICROPHONE, VoiceClient::NOT_SPEAKING],
            array_column(array_column($this->gatewayPayloads, 'd'), 'speaking'),
        );
        $answer = $this->decodeSentAudio();
        $this->assertGreaterThan(0.2, $this->seconds($answer), 'The bot had started its answer.');
        $this->assertLessThan(0.95, $this->seconds($answer), 'It did not finish its first sentence.');

        // The call went on, though the voice client never said the sentence was over: what Alice did was transcribed,
        // after the answer she interrupted, which is in the transcript and the text chat as a whole.
        $this->assertStringEndsWith("] Claude: It is a quarter past four. Time for a cup of tea.\n", $this->transcript($session));
        $this->assertSame("> **Alice:** Hey Claude, what time is it?\nIt is a quarter past four. Time for a cup of tea.", $this->sent[0]);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testKeepsNoCopyOfAnyonesAudioInTheTempFolder(): void
    {
        // This is about the audio: whisper hears nothing in it.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => '']);
        (new OptOuts())->add('666');
        $carol = self::BOB_SSRC + 1;
        $session = VoiceSession::start($vc = $this->connectedVoiceClient($channel = $this->voiceChannel(), decoders: true), $channel, $this->discord);

        // Bob opted out before the call. Alice does after she spoke, and Carol (777) never does.
        $frames = $this->opusFrames(440);
        foreach ([self::ALICE_SSRC => '555', self::BOB_SSRC => '666', $carol => '777'] as $ssrc => $userId) {
            $this->announceSpeaker($vc, $ssrc, $userId);
            $this->sendAudio($frames, from: $ssrc, to: $this->udp->getLocalAddress());
        }
        $this->waitUntil(fn () => count($this->logged('Transcribed')) === 2, 'Alice and Carol to have spoken', timeout: 20.0);
        $this->assertSame(['555', '777'], array_column($this->logged('Utterance ended'), 'user'));

        // The voice client's ffmpeg decoders write what each speaker says to the temp folder, at the latest
        // once they are closed, as they all are when the call ends. Carol's copy is a recording of her.
        $vc->voiceDecoders[$carol]->close();
        $this->assertCount(1, $copies = $this->decoderFiles($carol));
        $this->assertGreaterThan(1000, filesize($copies[0]));

        VoiceSession::optOut('555');
        await($session->stop());

        // Nothing is left there of anyone once the call ends, and only Carol's recording is in the call's folder.
        $this->assertSame([], $this->decoderFiles(self::ALICE_SSRC));
        $this->assertSame([], $this->decoderFiles(self::BOB_SSRC));
        $this->assertSame([], $this->decoderFiles($carol));
        $this->assertSame(["{$session->directory}/777-2.wav"], glob("{$session->directory}/*.wav"));
        $this->assertSame([], $this->loggedProblems());
    }

    /**
     * A voice client connected to the stand-in media server. Its voice gateway connection is faked:
     * what it sends there is collected in {@see $gatewayPayloads}.
     *
     * @param bool $decoders Whether it starts its ffmpeg process for each speaker. That process only feeds
     *                       'channel-opus' events and leaves a file in the temp directory; the WAV recording
     *                       doesn't use it.
     */
    private function connectedVoiceClient(Channel $channel, bool $decoders = false): Client
    {
        $vc = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['close', ...($decoders ? [] : ['createDecoder'])])->getMock();
        $vc->expects($this->once())->method('close');

        if (! $decoders) {
            $vc->method('createDecoder')->willReturnCallback(function (object $ss) use ($vc): void {
                $vc->voiceDecoders[$ss->ssrc] = $this->decoderProcess();
            });
        }

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
            $this->payloadTimes[] = hrtime(true) / 1e9;
        });
        $this->setProperty($ws, WS::class, 'socket', $socket);

        $this->udp = new UDP(Loop::get(), stream_socket_client('udp://' . $this->mediaServer->getLocalAddress()), ws: $ws);
        $this->udp->handleMessages($this->secretKey);
        $vc->udp = $this->udp;

        return $vc;
    }

    /**
     * @return list<string> The files the voice client's ffmpeg decoder left for a speaker, named <date>_<time>-<SSRC>.ogg.
     */
    private function decoderFiles(int $ssrc): array
    {
        return glob(sys_get_temp_dir() . "/*-{$ssrc}.ogg");
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
     * @param int $offset How many frames the speaker has sent before these.
     */
    private function sendAudio(array $frames, int $from, string $to, int $offset = 0): void
    {
        foreach ($frames as $i => $frame) {
            Loop::addTimer($i * 0.02, function () use ($frame, $i, $from, $to, $offset) {
                $i += $offset;
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
