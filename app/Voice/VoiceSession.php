<?php

declare(strict_types=1);

namespace App\Voice;

use App\Analytics\Usage;
use App\Settings\GuildSettings;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Voice\Processes\ProcessAbstract;
use Discord\Voice\Recording\RecordingFormat;
use Discord\Voice\VoiceClient;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use Throwable;

use function React\Promise\resolve;

/**
 * Records a voice channel and lets the people in it talk to Claude.
 *
 * Every speaker is recorded to their own WAV file. Meanwhile each utterance is transcribed
 * with whisper.cpp, and when it mentions the wake word, Claude's answer is posted in the
 * text channel and spoken back into the call with Piper.
 *
 * Each step is logged with the session's ID and how long it took, and the call's usage is
 * recorded for /stats. Neither includes what anyone said: that is only in transcript.txt.
 */
final class VoiceSession
{
    /** Transcript lines given to Claude as context. */
    private const int CONTEXT_LINES = 20;

    /** @var array<string, self> Active sessions by guild ID. */
    private static array $sessions = [];

    private UtteranceSplitter $splitter;

    private TimerInterface $ticker;

    /** Utterances are handled one at a time, in the order they ended. */
    private PromiseInterface $queue;

    /** @var list<string> */
    private array $transcript = [];

    private int $files = 0;

    private bool $stopped = false;

    /** Identifies the call in the logs and statistics. */
    public readonly string $id;

    private readonly float $startedAt;

    /** @var array<string, true> */
    private array $speakers = [];

    /** @var array{utterances: int, answers: int, failures: int} */
    private array $counts = ['utterances' => 0, 'answers' => 0, 'failures' => 0];

    private function __construct(
        private readonly VoiceClient $vc,
        private readonly Channel $textChannel,
        private readonly Discord $discord,
        public readonly string $directory,
        private readonly Transcriber $transcriber,
        private readonly Claude $claude,
        private readonly Speech $speech,
        public readonly string $wakeWord,
        private readonly Usage $usage,
    ) {
        $this->id = bin2hex(random_bytes(4));
        $this->startedAt = microtime(true);
        $this->queue = resolve(null);
        $this->splitter = new UtteranceSplitter("{$directory}/utterances", $this->queueUtterance(...));
    }

    /**
     * Returns what is missing to run a session, or null when everything is set up.
     *
     * @param array{wake_word: ?string, language: ?string, voice: ?string, model: ?string} $settings The server's settings.
     */
    public static function missingSetup(array $settings): ?string
    {
        $transcriber = Transcriber::fromEnv();
        $speech = Speech::fromEnv($settings['voice']);

        foreach ([$transcriber->binary, Claude::fromEnv()->binary, $speech->binary, $speech->ffmpeg] as $binary) {
            if (ProcessAbstract::checkForExecutable($binary) === null) {
                return "`{$binary}` was not found. Install it or set its path in .env.";
            }
        }

        if (! is_file($transcriber->model)) {
            return 'WHISPER_MODEL in .env does not point to a model file.';
        }

        if (! is_file($speech->model)) {
            return $settings['voice'] === null
                ? 'PIPER_MODEL in .env does not point to a model file.'
                : "The voice `{$settings['voice']}` is no longer installed. Choose another one with /settings.";
        }

        return null;
    }

    /**
     * The wake word of the servers that didn't choose their own. Empty answers everything.
     */
    public static function defaultWakeWord(): string
    {
        return trim(env('VOICE_WAKE_WORD', 'claude'));
    }

    public static function forGuild(string $guildId): ?self
    {
        return self::$sessions[$guildId] ?? null;
    }

    /**
     * Starts recording the channel the voice client is connected to.
     *
     * A call keeps the settings it starts with: changing them applies from the next call.
     *
     * @param Channel $textChannel Where Claude's answers are posted.
     * @param array{wake_word: ?string, language: ?string, voice: ?string, model: ?string}|null $settings
     *        The server's settings, when they were already read.
     */
    public static function start(VoiceClient $vc, Channel $textChannel, Discord $discord, ?array $settings = null): self
    {
        $settings ??= (new GuildSettings($discord->getLogger()))->for((string) $vc->channel->guild_id);
        $directory = sprintf(
            '%s/%s/%s',
            rtrim(env('RECORDINGS_PATH', 'recordings'), '/'),
            $vc->channel->guild_id,
            date('Y-m-d_H-i-s'),
        );
        mkdir($directory, 0755, true);

        $session = new self(
            $vc,
            $textChannel,
            $discord,
            $directory,
            Transcriber::fromEnv($settings['language']),
            Claude::fromEnv($settings['model']),
            Speech::fromEnv($settings['voice']),
            $settings['wake_word'] ?? self::defaultWakeWord(),
            new Usage($discord->getLogger()),
        );
        $session->listen();
        $session->log('info', 'Voice session started', ['channel' => $vc->channel->id, 'directory' => $directory]);
        $session->track(Usage::CALL_STARTED, ['channel' => $vc->channel->id]);

        return self::$sessions[$vc->channel->guild_id] = $session;
    }

    /**
     * Whether the text mentions the wake word. An empty wake word matches everything.
     */
    public static function mentions(string $text, string $wakeWord): bool
    {
        return $wakeWord === '' || preg_match('/\b' . preg_quote($wakeWord, '/') . '\b/iu', $text) === 1;
    }

    /**
     * Stops recording and leaves the call. Safe to call more than once.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        unset(self::$sessions[$this->vc->channel->guild_id]);
        $this->discord->getLoop()->cancelTimer($this->ticker);

        // Speech still in progress is transcribed for the transcript, but no longer answered.
        $this->splitter->flushAll();

        try {
            // Finalizes every speaker's WAV file.
            $this->vc->stopRecording();
        } catch (Throwable $e) {
            $this->log('warning', 'Could not stop recording cleanly: ' . $e->getMessage());
        }

        if ($this->vc->isReady()) {
            $this->vc->close();
        }

        $ms = $this->msSince($this->startedAt);
        $this->log('info', 'Voice session stopped', ['ms' => $ms, 'speakers' => count($this->speakers), ...$this->counts]);
        $this->track(Usage::CALL_ENDED, ['duration_ms' => $ms]);
    }

    private function listen(): void
    {
        // record() calls this when a speaker's first audio arrives, right after the voice client
        // created that speaker's receive stream. The stream is tapped here to feed the splitter,
        // while record() itself keeps writing the speaker's full recording to the returned path.
        $this->vc->record(RecordingFormat::WAV, function (string $userId): string {
            $stream = $this->vc->getReceiveStream($userId);
            $this->speakers[$userId] = true;
            $this->log('info', 'Recording a speaker', ['user' => $userId]);

            if ($stream === null) {
                $this->log('warning', "No receive stream for {$userId}; their speech will not be answered.", ['user' => $userId]);
            }

            $stream?->on('pcm', fn (string $pcm) => $this->splitter->push($userId, $pcm, microtime(true)));

            // Someone who leaves and rejoins gets a new stream, so every stream gets its own file.
            return sprintf('%s/%s-%d.wav', $this->directory, $userId, ++$this->files);
        });

        $this->ticker = $this->discord->getLoop()->addPeriodicTimer(
            0.25,
            fn () => $this->splitter->flushSilent(microtime(true)),
        );

        // Also clean up when someone else disconnects the bot from the call.
        $this->vc->once('close', $this->stop(...));
    }

    private function queueUtterance(string $userId, string $wavPath, float $seconds): void
    {
        $endedAt = microtime(true);
        $ms = (int) round($seconds * 1000);
        $this->counts['utterances']++;
        $this->log('info', 'Utterance ended', ['user' => $userId, 'ms' => $ms]);
        $this->track(Usage::UTTERANCE, ['user' => $userId, 'duration_ms' => $ms]);

        $this->queue = $this->queue
            ->then(fn () => $this->handleUtterance($userId, $wavPath, $endedAt))
            ->catch(function (Throwable $e) use ($userId) {
                $this->counts['failures']++;
                $this->log('error', 'Voice reply failed: ' . $e->getMessage(), ['user' => $userId]);
                $this->track(Usage::FAILED, ['user' => $userId]);
            });
    }

    /**
     * @param float $endedAt When the utterance ended, to time the answer from.
     */
    private function handleUtterance(string $userId, string $wavPath, float $endedAt): PromiseInterface
    {
        $transcribing = microtime(true);

        return $this->transcriber->transcribe($wavPath)
            ->finally(fn () => unlink($wavPath))
            ->then(function (string $text) use ($userId, $endedAt, $transcribing) {
                $this->log('info', 'Transcribed', ['user' => $userId, 'ms' => $this->msSince($transcribing), 'characters' => mb_strlen($text)]);

                if ($text === '') {
                    return null;
                }

                $name = $this->nameOf($userId);
                $this->remember("{$name}: {$text}");

                if ($this->stopped || ! self::mentions($text, $this->wakeWord)) {
                    $this->log('debug', 'Not answering', ['user' => $userId, 'reason' => $this->stopped ? 'the session stopped' : 'Claude was not addressed']);

                    return null;
                }

                $asking = microtime(true);

                return $this->claude->ask($this->prompt($name))->then(
                    function (string $answer) use ($userId, $name, $text, $endedAt, $asking) {
                        $this->log('info', 'Claude answered', ['user' => $userId, 'ms' => $this->msSince($asking), 'characters' => mb_strlen($answer)]);

                        return $this->reply($userId, $name, $text, $answer, $endedAt);
                    },
                    function (Throwable $e) {
                        $this->post("Sorry, I couldn't get an answer from Claude. ({$e->getMessage()})");

                        throw $e;
                    },
                );
            });
    }

    private function reply(string $userId, string $name, string $question, string $answer, float $endedAt): PromiseInterface
    {
        $this->remember("Claude: {$answer}");
        $this->post("> **{$name}:** {$question}\n{$answer}");
        $this->counts['answers']++;
        $this->track(Usage::ANSWERED, ['user' => $userId, 'duration_ms' => $this->msSince($endedAt)]);

        if ($this->stopped) {
            return resolve(null);
        }

        // Replies are kept next to the recordings, so the bot's side of the call is saved too.
        $oggPath = sprintf('%s/claude-%d.ogg', $this->directory, ++$this->files);
        $synthesizing = microtime(true);

        return $this->speech->synthesize($answer, $oggPath)
            ->then(function () use ($userId, $oggPath, $synthesizing) {
                $this->log('info', 'Speaking the answer', ['user' => $userId, 'synthesis_ms' => $this->msSince($synthesizing)]);

                return $this->vc->playFile($oggPath);
            });
    }

    private function prompt(string $name): string
    {
        return "Transcript of the voice call so far:\n\n"
            . implode("\n", $this->transcript)
            . "\n\n{$name} is talking to you. Reply to their last message.";
    }

    private function remember(string $line): void
    {
        $this->transcript[] = $line;
        $this->transcript = array_slice($this->transcript, -self::CONTEXT_LINES);

        file_put_contents("{$this->directory}/transcript.txt", date('[H:i:s] ') . $line . PHP_EOL, FILE_APPEND);
    }

    private function post(string $content): void
    {
        $message = MessageBuilder::new()
            ->setContent(mb_substr($content, 0, 2000))
            // Transcribed speech and Claude's answers must never ping anyone.
            ->setAllowedMentions(['parse' => []]);

        $this->textChannel->sendMessage($message)->catch(function (Throwable $e) {
            $this->log('warning', 'Could not post in the text channel: ' . $e->getMessage());
        });
    }

    /**
     * Logs a step of the call, with what identifies the call.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $this->discord->getLogger()->log($level, $message, ['guild' => $this->vc->channel->guild_id, 'session' => $this->id, ...$context]);
    }

    /**
     * Records something that happened in the call, for /stats.
     *
     * @param array{channel?: string, user?: string, duration_ms?: int} $details
     */
    private function track(string $type, array $details = []): void
    {
        $this->usage->record($type, (string) $this->vc->channel->guild_id, ['session' => $this->id, ...$details]);
    }

    private function msSince(float $time): int
    {
        return (int) round((microtime(true) - $time) * 1000);
    }

    private function nameOf(string $userId): string
    {
        return $this->vc->channel->guild?->members->get('id', $userId)?->displayname
            ?? $this->discord->users->get('id', $userId)?->displayname
            ?? $userId;
    }
}
