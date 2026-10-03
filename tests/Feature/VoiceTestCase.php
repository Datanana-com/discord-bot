<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Analytics\Usage;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\Channel;
use Discord\Voice\Processes\OpusDecoderInterface;
use Discord\Voice\Rtp\Packet;
use Discord\Voice\Speaking;
use Discord\Voice\VoiceClient;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use ReflectionClass;
use ReflectionProperty;
use Tests\UsesStatsDatabase;

use function React\Async\await;
use function React\Async\delay;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Runs the real voice code against a fake Discord: nothing connects to Discord, Opus
 * decoding is replaced by fixed PCM frames, and whisper.cpp, Claude Code and Piper are
 * replaced by the scripts in tests/Fixtures. Everything in between is the real code,
 * including DiscordPHP-Voice's receive path and WAV recording.
 */
abstract class VoiceTestCase extends TestCase
{
    use UsesStatsDatabase;

    protected const string GUILD_ID = '100';

    protected const array MEMBERS = ['555' => 'Alice', '666' => 'Bob'];

    protected string $recordings;

    protected string $claudeLog;

    protected TestHandler $logs;

    protected Discord $discord;

    /** @var list<string> Messages posted in the voice channel's text chat. */
    protected array $sent = [];

    /** @var list<string> Files played into the call. */
    protected array $played = [];

    /** When set, posting in the text channel fails with this error. */
    protected ?\Throwable $sendError = null;

    /** @var array<int, true> SSRCs that already sent a speaking event. */
    private array $speaking = [];

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $fixtures = dirname(__DIR__) . '/Fixtures';
        $this->recordings = sys_get_temp_dir() . '/voice-feature-' . uniqid();
        mkdir("{$this->recordings}/models", 0755, true);
        touch("{$this->recordings}/models/ggml-base.bin");
        touch("{$this->recordings}/models/voice.onnx");
        $this->claudeLog = "{$this->recordings}/claude.log";

        $this->setEnv([
            'RECORDINGS_PATH' => $this->recordings,
            'VOICE_WAKE_WORD' => 'claude',
            'WHISPER_BINARY' => "{$fixtures}/fake-whisper",
            'WHISPER_MODEL' => "{$this->recordings}/models/ggml-base.bin",
            'CLAUDE_BINARY' => "{$fixtures}/fake-claude",
            'CLAUDE_MODEL' => 'haiku',
            'PIPER_BINARY' => "{$fixtures}/fake-piper",
            'PIPER_MODEL' => "{$this->recordings}/models/voice.onnx",
            'FFMPEG_BINARY' => "{$fixtures}/fake-ffmpeg",
        ]);
        $this->setProcessEnv([
            'FAKE_CLAUDE_LOG' => $this->claudeLog,
            'FAKE_CLAUDE_OUTPUT' => json_encode(['type' => 'result', 'is_error' => false, 'result' => 'It is a quarter past four.']),
            'FAKE_CLAUDE_EXIT' => '0',
            'FAKE_WHISPER_OUTPUT' => 'Hey Claude, what time is it?',
        ]);

        $this->useStatsDatabase();
        $this->logs = new TestHandler();
        $logger = new Logger('test', [$this->logs]);
        $discord = static::getStubBuilder(Discord::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getLogger', 'getLoop', 'joinVoiceChannel'])
            ->getStub();
        $discord->method('getLogger')->willReturn($logger);
        $discord->method('getLoop')->willReturn(Loop::get());
        $this->discord = $discord;
    }

    protected function tearDown(): void
    {
        VoiceSession::forGuild(self::GUILD_ID)?->stop();

        foreach ($this->originalEnv as $name => $value) {
            if (str_starts_with($name, 'FAKE_')) {
                putenv($value === false ? $name : "{$name}={$value}");
            } elseif ($value === false) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }

        exec('rm -rf ' . escapeshellarg($this->recordings));
    }

    /**
     * Sets bot settings, as if they were in .env.
     *
     * @param array<string, string> $values
     */
    protected function setEnv(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->originalEnv[$name] ??= $_ENV[$name] ?? false;
            $_ENV[$name] = $value;
        }
    }

    /**
     * Sets environment variables for the fake programs.
     *
     * @param array<string, string> $values
     */
    protected function setProcessEnv(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->originalEnv[$name] ??= getenv($name);
            putenv("{$name}={$value}");
        }
    }

    /**
     * A voice channel whose members are {@see MEMBERS}. Messages sent to it are collected in {@see $sent}.
     */
    protected function voiceChannel(): Channel
    {
        $members = new class (self::MEMBERS) {
            /** @param array<string, string> $names */
            public function __construct(private array $names)
            {
            }

            public function get(string $key, string $id): ?object
            {
                return isset($this->names[$id]) ? (object) ['displayname' => $this->names[$id]] : null;
            }
        };

        $channel = static::getStubBuilder(Channel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get', '__isset', 'sendMessage'])
            ->getStub();
        $attributes = fn (string $name) => match ($name) {
            'id' => '200',
            'guild_id' => self::GUILD_ID,
            'guild' => (object) ['members' => $members],
            default => null,
        };
        $channel->method('__get')->willReturnCallback($attributes);
        $channel->method('__isset')->willReturnCallback(fn (string $name) => $attributes($name) !== null);
        $channel->method('sendMessage')->willReturnCallback(function (MessageBuilder $message): PromiseInterface {
            // Messages repeat what people said and what Claude answered, so they must never ping anyone.
            $this->assertSame(['parse' => []], $message->jsonSerialize()['allowed_mentions'] ?? null, 'Mentions are disabled.');
            $this->sent[] = $message->getContent();

            return $this->sendError === null ? resolve(null) : reject($this->sendError);
        });

        return $channel;
    }

    /**
     * A voice client connected to the channel. Only its network side and Opus decoding are faked;
     * files it is asked to play are collected in {@see $played}.
     *
     * @param bool $connected            Whether it reports being connected; it then expects to be closed exactly once.
     * @param bool $findsReceiveStreams Whether getReceiveStream() finds speakers' streams.
     */
    protected function voiceClient(Channel $channel, bool $connected = false, bool $findsReceiveStreams = true): VoiceClient
    {
        $methods = ['createDecoder', 'playFile', 'isReady', 'close', ...($findsReceiveStreams ? [] : ['getReceiveStream'])];

        if ($connected) {
            $vc = $this->getMockBuilder(VoiceClient::class)->disableOriginalConstructor()->onlyMethods($methods)->getMock();
            $vc->expects($this->once())->method('close');
        } else {
            $vc = static::getStubBuilder(VoiceClient::class)->disableOriginalConstructor()->onlyMethods($methods)->getStub();
        }

        $vc->discord = $this->discord;
        $vc->channel = $channel;
        $vc->voiceDecoders = [];
        $vc->receiveStreams = [];
        $this->setProperty($vc, VoiceClient::class, 'speakingStatus', Collection::for(Speaking::class, 'ssrc'));
        $this->setProperty($vc, VoiceClient::class, 'ssrcToUserId', []);
        $this->setProperty($vc, VoiceClient::class, 'readOpusTimer', null);
        $this->setProperty($vc, VoiceClient::class, 'startTime', 0);

        $vc->method('isReady')->willReturn($connected);

        if (! $findsReceiveStreams) {
            $vc->method('getReceiveStream')->willReturn(null);
        }

        $vc->method('playFile')->willReturnCallback(function (string $file): PromiseInterface {
            $this->played[] = $file;

            return resolve(null);
        });
        // The ffmpeg decoder process is not needed: PCM comes from the Opus decoder below.
        $vc->method('createDecoder')->willReturnCallback(function (object $ss) use ($vc): void {
            $vc->voiceDecoders[$ss->ssrc] = $this->decoderProcess();
        });
        // Stands in for libopus: every packet decodes to one 20 ms frame of 48 kHz stereo PCM.
        $vc->opusdecoder = new class () implements OpusDecoderInterface {
            public function decode($data, int $channels = 2, int $audioRate = 48000): string
            {
                return str_repeat("\x10\x00", 1920);
            }
        };

        return $vc;
    }

    /**
     * Stands in for the ffmpeg process the voice client starts for each speaker.
     */
    protected function decoderProcess(): object
    {
        return new class () {
            public object $stdin;

            public function __construct()
            {
                $this->stdin = new class () {
                    public function isWritable(): bool
                    {
                        return true;
                    }

                    public function write(string $data): bool
                    {
                        return true;
                    }
                };
            }

            public function close(): void
            {
            }

            public function isRunning(): bool
            {
                return false;
            }
        };
    }

    /**
     * Sends a member's speech through the voice client's receive path, as 20 ms RTP packets.
     */
    protected function speak(VoiceClient $vc, int $ssrc, string $userId, float $seconds): void
    {
        if (! isset($this->speaking[$ssrc])) {
            $this->announceSpeaker($vc, $ssrc, $userId);
            $this->speaking[$ssrc] = true;
        }

        for ($frame = 0; $frame < $seconds * 50; $frame++) {
            $packet = (new ReflectionClass(Packet::class))->newInstanceWithoutConstructor();
            $this->setProperty($packet, Packet::class, 'ssrc', $ssrc);
            $this->setProperty($packet, Packet::class, 'timestamp', $frame * 960);
            $packet->decryptedAudio = str_repeat("\xAB", 40);
            $vc->handleAudioData($packet);
        }
    }

    /**
     * Sends the voice gateway's speaking event, which tells the voice client whose audio an SSRC carries.
     */
    protected function announceSpeaker(VoiceClient $vc, int $ssrc, string $userId): void
    {
        $speaking = (new ReflectionClass(Speaking::class))->newInstanceWithoutConstructor();
        $this->setProperty($speaking, Speaking::class, 'attributes', ['ssrc' => $ssrc, 'user_id' => $userId, 'speaking' => 1, 'delay' => 0]);
        $vc->updateSpeakingStatus($speaking);
    }

    /**
     * Runs the event loop until the condition holds, failing the test after the timeout.
     *
     * The loop always runs through React\Async\await(), like in the unit tests: running it with
     * Loop::run() as well would leave two runners, and await()'s one can pause mid-callback.
     */
    protected function waitUntil(callable $condition, string $what, float $timeout = 10.0): void
    {
        $done = new Deferred();
        $check = Loop::addPeriodicTimer(0.05, function () use ($condition, $done) {
            if ($condition()) {
                $done->resolve(null);
            }
        });
        $deadline = Loop::addTimer($timeout, fn () => $done->resolve(null));

        await($done->promise());
        Loop::cancelTimer($check);
        Loop::cancelTimer($deadline);

        $this->assertTrue((bool) $condition(), "Timed out waiting for {$what}.");
    }

    /**
     * Runs the event loop for a while, to show that something does not happen.
     */
    protected function runFor(float $seconds): void
    {
        delay($seconds);
    }

    protected function transcript(VoiceSession $session): string
    {
        $path = "{$session->directory}/transcript.txt";

        return is_file($path) ? file_get_contents($path) : '';
    }

    /**
     * @return list<array<string, mixed>> The context of each time the message was logged.
     */
    protected function logged(string $message): array
    {
        return array_values(array_map(
            fn ($record) => $record->context,
            array_filter($this->logs->getRecords(), fn ($record) => $record->message === $message),
        ));
    }

    /**
     * @return array<string, mixed> This server's usage statistics.
     */
    protected function usage(): array
    {
        return (new Usage(new Logger('test')))->summary(self::GUILD_ID);
    }

    /**
     * @return list<string> Warnings and errors the bot logged.
     */
    protected function loggedProblems(): array
    {
        return array_values(array_map(
            fn ($record) => $record->message,
            array_filter($this->logs->getRecords(), fn ($record) => $record->level->value >= Level::Warning->value),
        ));
    }

    protected function assertWavDuration(float $seconds, string $path): void
    {
        $this->assertFileExists($path);
        $this->assertSame(44 + (int) round($seconds * 48000 * 4), filesize($path), "{$path} should hold {$seconds}s of audio.");
    }

    protected function setProperty(object $object, string $class, string $property, mixed $value): void
    {
        (new ReflectionProperty($class, $property))->setValue($object, $value);
    }
}
