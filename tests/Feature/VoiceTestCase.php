<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Analytics\Usage;
use App\Assistant\Memory;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\Channel;
use Discord\Voice\Exceptions\Channels\AudioAlreadyPlayingException;
use Discord\Voice\Processes\OpusDecoderInterface;
use Discord\Voice\Rtp\Packet;
use Discord\Voice\Rtp\UDP;
use Discord\Voice\Speaking;
use Discord\Voice\VoiceClient;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use ReflectionClass;
use ReflectionProperty;
use Tests\FakesClaudeOutput;
use Tests\UsesStatsDatabase;

use function React\Async\await;
use function React\Async\delay;
use function React\Promise\all;
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
    use FakesClaudeOutput;
    use UsesStatsDatabase;

    protected const string GUILD_ID = '100';

    protected const array MEMBERS = ['555' => 'Alice', '666' => 'Bob', '777' => 'Carol'];

    /** Another bot of the server, which plays music in voice channels. Discord says of it that it is a bot. */
    protected const array BOTS = ['1234' => 'Jukebox'];

    /** The bot's own user ID: it is in the voice channel too, but it doesn't count as someone there. */
    protected const string BOT_ID = '999';

    protected string $recordings;

    protected string $claudeLog;

    /** Once FAKE_CLAUDE_PAUSE is set, Claude's stand-in stops after its first line until this file exists. */
    protected string $claudeResume;

    /** Every time Claude's stand-in ran, one after the other: see {@see claudeCalls()}. */
    protected string $claudeCalls;

    /** The PID of every stand-in of Claude that was started to wait for a question: see {@see waitingClaudes()}. */
    protected string $claudeWaiting;

    /** The PID of every stand-in of Piper that was started: see {@see pipers()}. */
    protected string $piperRunning;

    /** While this file exists, Claude's stand-in doesn't answer when it is asked for a new memory. */
    protected string $claudeHold;

    /** While this file exists, whisper.cpp's stand-in doesn't print what was said. */
    protected string $whisperHold;

    /** Where the bot keeps its memories. */
    protected string $memories;

    /** @var \ArrayObject<int, object> The voice states of the server, as the bot's cache holds them: who is in which voice channel. */
    protected \ArrayObject $voiceStates;

    protected TestHandler $logs;

    protected Discord $discord;

    /** @var list<string> Messages posted in the voice channel's text chat. */
    protected array $sent = [];

    /** @var list<string> Files played into the call. */
    protected array $played = [];

    /** @var list<string> The files among them that were being played when the voice client was told to stop. */
    protected array $cutOff = [];

    /** When set, posting in the text channel fails with this error. */
    protected ?\Throwable $sendError = null;

    /** When set, the voice client can't play a file, and fails with this error. */
    protected ?\Throwable $playError = null;

    /** When set, a message only arrives in the text channel once this resolves. */
    protected ?PromiseInterface $sending = null;

    /** When set, a file played into the call only finishes once this resolves. */
    protected ?PromiseInterface $playing = null;

    /** How long a file played into the call takes otherwise. */
    protected float $playSeconds = 0.0;

    /** @var list<array{0: float, 1: string}> When each packet was sent into the call, and the packet, by a voice client that sends packets. */
    protected array $packets = [];

    /** @var list<array{0: float, 1: int}> When such a voice client was told the bot speaks, or stopped, and which. */
    protected array $speakingFlags = [];

    /** @var array<int, true> SSRCs that already sent a speaking event. */
    private array $speaking = [];

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    /** @var array<string, string|false> */
    private array $originalProcessEnv = [];

    protected function setUp(): void
    {
        $fixtures = dirname(__DIR__) . '/Fixtures';
        $this->recordings = sys_get_temp_dir() . '/voice-feature-' . uniqid();
        mkdir("{$this->recordings}/models", 0755, true);
        touch("{$this->recordings}/models/ggml-base.bin");
        touch("{$this->recordings}/models/voice.onnx");
        $this->claudeLog = "{$this->recordings}/claude.log";
        $this->claudeResume = "{$this->recordings}/claude.resume";
        $this->claudeCalls = "{$this->recordings}/claude.calls";
        $this->claudeWaiting = "{$this->recordings}/claude.waiting";
        $this->piperRunning = "{$this->recordings}/piper.running";
        $this->claudeHold = "{$this->recordings}/claude.hold";
        $this->whisperHold = "{$this->recordings}/whisper.hold";
        $this->memories = "{$this->recordings}/memories";
        $this->voiceStates = new \ArrayObject();

        $this->setEnv([
            'MEMORY_PATH' => $this->memories,
            'RECORDINGS_PATH' => $this->recordings,
            'VOICE_WAKE_WORD' => 'claude',
            'WHISPER_BINARY' => "{$fixtures}/fake-whisper",
            'WHISPER_MODEL' => "{$this->recordings}/models/ggml-base.bin",
            'CLAUDE_BINARY' => "{$fixtures}/fake-claude",
            'CLAUDE_MODEL' => 'haiku',
            'PIPER_BINARY' => "{$fixtures}/fake-piper",
            'PIPER_MODEL' => "{$this->recordings}/models/voice.onnx",
            'FFMPEG_BINARY' => "{$fixtures}/fake-ffmpeg",
            // The voice client plays nothing: the files handed to it are collected in $played. The bot's own
            // player would send their packets, and fake-ffmpeg's files hold no Ogg Opus to send.
            'VOICE_PLAYER' => 'library',
        ]);
        $this->setProcessEnv([
            'FAKE_ENV' => "{$this->recordings}/fake.env",
            'FAKE_CLAUDE_LOG' => $this->claudeLog,
            'FAKE_CLAUDE_CALLS' => $this->claudeCalls,
            'FAKE_CLAUDE_WAITING' => $this->claudeWaiting,
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter', ' past four.'),
            'FAKE_CLAUDE_EXIT' => '0',
            'FAKE_CLAUDE_PAUSE' => '0',
            'FAKE_CLAUDE_RESUME' => $this->claudeResume,
            'FAKE_PIPER_RUNNING' => $this->piperRunning,
            'FAKE_CLAUDE_HOLD' => $this->claudeHold,
            'FAKE_WHISPER_OUTPUT' => 'Hey Claude, what time is it?',
            'FAKE_WHISPER_HOLD' => $this->whisperHold,
        ]);

        $this->useStatsDatabase();
        $this->logs = new TestHandler();
        $logger = new Logger('test', [$this->logs]);
        $discord = static::getStubBuilder(Discord::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getLogger', 'getLoop', 'joinVoiceChannel', '__get'])
            ->getStub();
        $discord->method('__get')->willReturnCallback(fn (string $name) => match ($name) {
            'id' => self::BOT_ID,
            'users' => $this->userNames(),
            default => null,
        });
        $discord->method('getLogger')->willReturn($logger);
        $discord->method('getLoop')->willReturn($this->loop());
        $this->discord = $discord;
    }

    protected function tearDown(): void
    {
        // A call is summarized after it stopped, which must be over before its recordings are deleted.
        await(all(array_map(fn (VoiceSession $session) => $session->stop(), VoiceSession::unfinished())));
        // A call a test left starting, as when the bot never got to join, is not starting in the next test.
        (new ReflectionProperty(VoiceSession::class, 'starting'))->setValue(null, []);
        // Nor is a bot that was stopped in one test still stopping in the next.
        (new ReflectionProperty(VoiceSession::class, 'refusing'))->setValue(null, false);

        foreach ($this->originalProcessEnv as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        foreach ($this->originalEnv as $name => $value) {
            if ($value === false) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }

        exec('rm -rf ' . escapeshellarg($this->recordings));
    }

    /**
     * The event loop the bot runs its timers on.
     */
    protected function loop(): LoopInterface
    {
        return Loop::get();
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
            $this->originalProcessEnv[$name] ??= getenv($name);
            putenv("{$name}={$value}");
        }

        // A program that keeps running, like the Claude Code process that waits for a question, has the environment
        // it was started with. The fake ones read from this file what a test sets after they started.
        $set = array_filter(array_keys($this->originalProcessEnv), fn (string $name) => str_starts_with($name, 'FAKE_'));
        file_put_contents("{$this->recordings}/fake.env", implode('', array_map(
            fn (string $name) => sprintf("%s='%s'\n", $name, str_replace("'", "'\\''", getenv($name))),
            $set,
        )));
    }

    /**
     * A voice channel whose members are {@see MEMBERS}. Messages sent to it are collected in {@see $sent}.
     *
     * @param string $id      The channel's ID: the bot's call is in 200.
     * @param string $guildId The server it is in.
     */
    protected function voiceChannel(string $id = '200', string $guildId = self::GUILD_ID): Channel
    {
        $members = $this->userNames();

        $channel = static::getStubBuilder(Channel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get', '__isset', 'sendMessage'])
            ->getStub();
        $attributes = fn (string $name) => match ($name) {
            'id' => $id,
            'guild_id' => $guildId,
            'guild' => (object) ['members' => $members, 'voice_states' => $this->voiceStates],
            default => null,
        };
        $channel->method('__get')->willReturnCallback($attributes);
        $channel->method('__isset')->willReturnCallback(fn (string $name) => $attributes($name) !== null);
        $channel->method('sendMessage')->willReturnCallback(function (MessageBuilder $message): PromiseInterface {
            // Messages repeat what people said and what Claude answered, so they must never ping anyone.
            $this->assertSame(['parse' => []], $message->jsonSerialize()['allowed_mentions'] ?? null, 'Mentions are disabled.');
            $this->sent[] = $message->getContent();

            return $this->sendError === null ? $this->sending ?? resolve(null) : reject($this->sendError);
        });

        return $channel;
    }

    /**
     * What looks up a user by ID in the bot's caches, for the members of {@see MEMBERS} and the
     * bots of {@see BOTS} unless told otherwise. Like Discord, it only says of a bot that it is one.
     *
     * @param array<string, string> $names The display name of each user, by ID.
     */
    protected function userNames(array $names = self::MEMBERS + self::BOTS): object
    {
        return new class ($names, self::BOTS) {
            /**
             * @param array<string, string> $names
             * @param array<string, string> $bots
             */
            public function __construct(private array $names, private array $bots)
            {
            }

            public function get(string $key, string $id): ?object
            {
                return isset($this->names[$id]) ? (object) ['displayname' => $this->names[$id], 'bot' => isset($this->bots[$id]) ? true : null] : null;
            }
        };
    }

    /**
     * Who is in the voice channel, as the bot's cache knows it: the bot, and these people. Nothing is
     * known of who is there until this is called, like when the bot's cache has no voice states yet.
     */
    protected function inCall(string ...$userIds): void
    {
        $this->voiceStates->exchangeArray([]);

        foreach ([self::BOT_ID, ...$userIds] as $userId) {
            $this->voiceStates[] = $this->inVoice($userId);
        }
    }

    /**
     * Someone's voice state: the voice channel they are in, and who they are when the bot's cache knows them.
     */
    protected function inVoice(string $userId, string $channelId = '200'): object
    {
        return (object) ['user_id' => $userId, 'channel_id' => $channelId, 'user' => $this->userNames()->get('id', $userId)];
    }

    /**
     * Someone leaves the voice channel: Discord then says they are in none.
     */
    protected function leaves(string $userId): void
    {
        foreach ($this->voiceStates as $state) {
            if ($state->user_id === $userId) {
                $state->channel_id = null;
            }
        }
    }

    /**
     * Someone joins the voice channel.
     */
    protected function joins(string $userId): void
    {
        $this->leaves($userId);
        $this->voiceStates[] = $this->inVoice($userId);
    }

    /**
     * Claude Code's output for an answer.
     */
    protected function claudeSays(string $answer): string
    {
        return json_encode(['type' => 'result', 'is_error' => false, 'result' => $answer]);
    }

    protected function memory(): Memory
    {
        return new Memory($this->memories);
    }

    /**
     * @return list<array{prompt: string, system: string, thinking: string, arguments: string, waited: bool, pid: int}>
     *         What Claude Code was given each time it was asked: the prompt, the system prompt on one line,
     *         MAX_THINKING_TOKENS in its environment ("unset" without it), and its arguments, one per line. Also
     *         whether the process had been waiting for its prompt, or was started with it, and the process's ID.
     */
    protected function claudeCalls(): array
    {
        if (! is_file($this->claudeCalls)) {
            return [];
        }

        $calls = [];

        foreach (array_slice(explode("=== call ===\n", file_get_contents($this->claudeCalls)), 1) as $call) {
            preg_match('/^stdin=(.*)\n\z/ms', $call, $prompt);
            preg_match('/^arg=--system-prompt\narg=(.*?)\narg=--tools$/ms', $call, $system);
            preg_match('/^thinking=(.*)$/m', $call, $thinking);
            preg_match('/^arg=.*(?=^stdin=)/ms', $call, $arguments);
            preg_match('/^pid=(\d+)$/m', $call, $pid);
            // A process that waits gets its prompt as a message, a JSON object on one line.
            $waited = str_contains($arguments[0] ?? '', "arg=--input-format\narg=stream-json\n");
            $calls[] = [
                'prompt' => $waited ? json_decode($prompt[1] ?? '', true)['message']['content'] ?? '' : $prompt[1] ?? '',
                'system' => preg_replace('/\s+/', ' ', $system[1] ?? ''),
                'thinking' => $thinking[1] ?? '',
                'arguments' => $arguments[0] ?? '',
                'waited' => $waited,
                'pid' => (int) ($pid[1] ?? 0),
            ];
        }

        return $calls;
    }

    /**
     * @return list<int> The process ID of every Claude Code that was started to wait for a question, oldest first.
     */
    protected function waitingClaudes(): array
    {
        return is_file($this->claudeWaiting) ? array_map(intval(...), file($this->claudeWaiting, FILE_IGNORE_NEW_LINES)) : [];
    }

    /**
     * @return list<int> The process ID of every Piper that was started, oldest first.
     */
    protected function pipers(): array
    {
        return is_file($this->piperRunning) ? array_map(intval(...), file($this->piperRunning, FILE_IGNORE_NEW_LINES)) : [];
    }

    /**
     * Whether a process is still there: running, or ended without the bot having noticed.
     */
    protected function isRunning(int $pid): bool
    {
        return posix_kill($pid, 0);
    }

    /**
     * @return list<array{prompt: string, system: string}> The times Claude Code was asked for a new memory, in order.
     */
    protected function memoryUpdates(): array
    {
        return array_values(array_filter($this->claudeCalls(), fn (array $call) => str_starts_with($call['system'], "You keep a Discord bot's memory")));
    }

    /**
     * From now on, Claude is still writing a new memory it is asked for, until {@see releaseMemoryUpdates()}.
     */
    protected function holdMemoryUpdates(): void
    {
        touch($this->claudeHold);
    }

    protected function releaseMemoryUpdates(): void
    {
        unlink($this->claudeHold);
    }

    /**
     * Someone says something, and it is still waiting to be transcribed, with whatever was said after it,
     * until {@see transcribe()}: time for people to come and go before Claude is asked.
     */
    protected function speakAndWait(VoiceClient $vc, string ...$userIds): void
    {
        touch($this->whisperHold);
        $ended = count($this->logged('Utterance ended'));

        foreach ($userIds as $userId) {
            $this->speak($vc, ssrc: (int) $userId, userId: $userId, seconds: 1.0);
        }

        $this->waitUntil(fn () => count($this->logged('Utterance ended')) === $ended + count($userIds), 'what was said to be over');
    }

    protected function transcribe(): void
    {
        unlink($this->whisperHold);
    }

    /**
     * A voice client connected to the channel. Only its network side and Opus decoding are faked;
     * files it is asked to play are collected in {@see $played}.
     *
     * @param bool $connected            Whether it reports being connected; it then expects to be closed exactly once.
     * @param bool $findsReceiveStreams Whether getReceiveStream() finds speakers' streams.
     * @param bool $sendsPackets        Whether the bot's own player can send packets through it: they are then collected
     *                                  in {@see $packets}, and what it is told about speaking in {@see $speakingFlags}.
     *                                  For a test that plays with VOICE_PLAYER=bot, and real Ogg Opus files.
     */
    protected function voiceClient(Channel $channel, bool $connected = false, bool $findsReceiveStreams = true, bool $sendsPackets = false): VoiceClient
    {
        $methods = ['createDecoder', 'playFile', 'stop', 'isReady', 'close', ...($findsReceiveStreams ? [] : ['getReceiveStream']), ...($sendsPackets ? ['setSpeaking'] : [])];

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

        if ($sendsPackets) {
            $vc->method('setSpeaking')->willReturnCallback(function (int $speaking): void {
                $this->speakingFlags[] = [microtime(true), $speaking];
            });
            // Stands in for the media connection: the packets go nowhere, and are kept with when they were sent.
            $vc->udp = new class (function (string $packet): void {
                $this->packets[] = [microtime(true), $packet];
            }) extends UDP {
                public function __construct(private readonly \Closure $record)
                {
                }

                public function sendBuffer(string $data): void
                {
                    ($this->record)($data);
                }
            };
        }

        // The file being played, when one is, and what is resolved when it has been.
        $busy = $finished = null;
        $vc->method('playFile')->willReturnCallback(function (string $file) use (&$busy, &$finished): PromiseInterface {
            if ($this->playError !== null) {
                return reject($this->playError);
            }

            // Like the voice library, which plays one file at a time.
            if ($busy !== null) {
                return reject(new AudioAlreadyPlayingException());
            }

            $busy = $file;
            $this->played[] = $file;
            $played = $finished = new Deferred();

            ($this->playing ?? $this->after($this->playSeconds))->then(function () use (&$busy, $file, $played) {
                // Like the voice library, which never says a file finished once it was told to stop playing it.
                if ($busy === $file) {
                    $busy = null;
                    $played->resolve(null);
                }
            });

            return $played->promise();
        });
        $vc->method('stop')->willReturnCallback(function () use (&$busy, &$finished): void {
            // Like the voice library.
            if ($busy === null) {
                throw new \RuntimeException('Audio must be playing to stop it.');
            }

            $this->cutOff[] = $busy;
            $busy = null;
            // Like the voice library when it is stopped before it has read the start of the file: mostly it
            // never says anything more about the file, but then it says that playing it failed.
            $finished->reject(new \RuntimeException('Buffer closed'));
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
     * A promise that resolves after a while, or right away when that is no time at all.
     */
    protected function after(float $seconds): PromiseInterface
    {
        if ($seconds <= 0) {
            return resolve(null);
        }

        $over = new Deferred();
        Loop::addTimer($seconds, fn () => $over->resolve(null));

        return $over->promise();
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

    /**
     * Someone says something to Claude and gets the answer.
     */
    protected function ask(VoiceClient $vc, string $userId, string $text): void
    {
        $answers = count($this->sent);
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => $text]);
        $this->speak($vc, ssrc: (int) $userId, userId: $userId, seconds: 1.0);
        $this->waitUntil(fn () => count($this->sent) > $answers, 'the answer');
    }

    /**
     * A prompt without the time of each line of the transcript, which is the time of the test.
     */
    protected function untimed(string $prompt): string
    {
        return preg_replace('/^\[\d\d:\d\d:\d\d\] /m', '', $prompt);
    }

    /**
     * The logs hold IDs, counts, lengths and durations, never what anyone wrote, what Claude answered or the memory.
     */
    protected function assertLogsNeverMention(string ...$texts): void
    {
        $logs = implode("\n", array_map(fn ($record) => $record->message . ' ' . json_encode($record->context), $this->logs->getRecords()));

        foreach ($texts as $text) {
            $this->assertStringNotContainsString($text, $logs);
        }
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
