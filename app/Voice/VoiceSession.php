<?php

declare(strict_types=1);

namespace App\Voice;

use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Voice\Processes\ProcessAbstract;
use Discord\Voice\Recording\RecordingFormat;
use Discord\Voice\Rtp\UDP;
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
 */
final class VoiceSession
{
    /** Transcript lines given to Claude as context. */
    private const int CONTEXT_LINES = 20;

    /** How often silence is sent to keep receiving the call's audio; Discord stops after about 5 minutes. */
    private const float SILENCE_INTERVAL_SECONDS = 60.0;

    /** @var array<string, self> Active sessions by guild ID. */
    private static array $sessions = [];

    private UtteranceSplitter $splitter;

    private TimerInterface $ticker;

    private TimerInterface $silenceTimer;

    /** Utterances are handled one at a time, in the order they ended. */
    private PromiseInterface $queue;

    /** @var list<string> */
    private array $transcript = [];

    private int $files = 0;

    private bool $stopped = false;

    private function __construct(
        private readonly VoiceClient $vc,
        private readonly Channel $textChannel,
        private readonly Discord $discord,
        public readonly string $directory,
        private readonly Transcriber $transcriber,
        private readonly Claude $claude,
        private readonly Speech $speech,
        private readonly string $wakeWord,
    ) {
        $this->queue = resolve(null);
        $this->splitter = new UtteranceSplitter("{$directory}/utterances", $this->queueUtterance(...));
    }

    /**
     * Returns what is missing to run a session, or null when everything is set up.
     */
    public static function missingSetup(): ?string
    {
        $transcriber = Transcriber::fromEnv();
        $speech = Speech::fromEnv();

        foreach ([$transcriber->binary, Claude::fromEnv()->binary, $speech->binary] as $binary) {
            if (ProcessAbstract::checkForExecutable($binary) === null) {
                return "`{$binary}` was not found. Install it or set its path in .env.";
            }
        }

        foreach (['WHISPER_MODEL' => $transcriber->model, 'PIPER_MODEL' => $speech->model] as $name => $path) {
            if (! is_file($path)) {
                return "{$name} in .env does not point to a model file.";
            }
        }

        return null;
    }

    public static function forGuild(string $guildId): ?self
    {
        return self::$sessions[$guildId] ?? null;
    }

    /**
     * Starts recording the channel the voice client is connected to.
     *
     * @param Channel $textChannel Where Claude's answers are posted.
     */
    public static function start(VoiceClient $vc, Channel $textChannel, Discord $discord): self
    {
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
            Transcriber::fromEnv(),
            Claude::fromEnv(),
            Speech::fromEnv(),
            trim(env('VOICE_WAKE_WORD', 'claude')),
        );
        $session->listen();

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
        $this->discord->getLoop()->cancelTimer($this->silenceTimer);

        // Speech still in progress is transcribed for the transcript, but no longer answered.
        $this->splitter->flushAll();

        try {
            // Finalizes every speaker's WAV file.
            $this->vc->stopRecording();
        } catch (Throwable $e) {
            $this->discord->getLogger()->warning('Could not stop recording cleanly: ' . $e->getMessage());
        }

        if ($this->vc->isReady()) {
            $this->vc->close();
        }
    }

    private function listen(): void
    {
        // record() calls this when a speaker's first audio arrives, right after the voice client
        // created that speaker's receive stream. The stream is tapped here to feed the splitter,
        // while record() itself keeps writing the speaker's full recording to the returned path.
        $this->vc->record(RecordingFormat::WAV, function (string $userId): string {
            $stream = $this->vc->getReceiveStream($userId);

            if ($stream === null) {
                $this->discord->getLogger()->warning("No receive stream for {$userId}; their speech will not be answered.");
            }

            $stream?->on('pcm', fn (string $pcm) => $this->splitter->push($userId, $pcm, microtime(true)));

            // Someone who leaves and rejoins gets a new stream, so every stream gets its own file.
            return sprintf('%s/%s-%d.wav', $this->directory, $userId, ++$this->files);
        });

        $this->ticker = $this->discord->getLoop()->addPeriodicTimer(
            0.25,
            fn () => $this->splitter->flushSilent(microtime(true)),
        );

        // Discord only sends a bot the call's audio after the bot has sent some itself, and stops
        // when it hasn't for a while.
        $this->sendSilence();
        $this->silenceTimer = $this->discord->getLoop()->addPeriodicTimer(self::SILENCE_INTERVAL_SECONDS, $this->sendSilence(...));

        // Also clean up when someone else disconnects the bot from the call.
        $this->vc->once('close', $this->stop(...));
    }

    /**
     * Sends a moment of silence into the call, unless the bot is already speaking.
     */
    private function sendSilence(): void
    {
        if (! $this->vc->isReady() || $this->vc->speaking !== VoiceClient::NOT_SPEAKING) {
            return;
        }

        // Discord expects a speaking update before any audio.
        $this->vc->setSpeaking(VoiceClient::MICROPHONE);

        for ($frame = 0; $frame < 5; $frame++) {
            $this->vc->udp->sendBuffer(UDP::SILENCE_FRAME);
        }

        $this->vc->setSpeaking(VoiceClient::NOT_SPEAKING);
    }

    private function queueUtterance(string $userId, string $wavPath): void
    {
        $this->queue = $this->queue
            ->then(fn () => $this->handleUtterance($userId, $wavPath))
            ->catch(function (Throwable $e) {
                $this->discord->getLogger()->error('Voice reply failed: ' . $e->getMessage());
            });
    }

    private function handleUtterance(string $userId, string $wavPath): PromiseInterface
    {
        return $this->transcriber->transcribe($wavPath)
            ->finally(fn () => unlink($wavPath))
            ->then(function (string $text) use ($userId) {
                if ($text === '') {
                    return null;
                }

                $name = $this->nameOf($userId);
                $this->remember("{$name}: {$text}");

                if ($this->stopped || ! self::mentions($text, $this->wakeWord)) {
                    return null;
                }

                return $this->claude->ask($this->prompt($name))->then(
                    fn (string $answer) => $this->reply($name, $text, $answer),
                    function (Throwable $e) {
                        $this->post("Sorry, I couldn't get an answer from Claude. ({$e->getMessage()})");

                        throw $e;
                    },
                );
            });
    }

    private function reply(string $name, string $question, string $answer): PromiseInterface
    {
        $this->remember("Claude: {$answer}");
        $this->post("> **{$name}:** {$question}\n{$answer}");

        if ($this->stopped) {
            return resolve(null);
        }

        // Replies are kept next to the recordings, so the bot's side of the call is saved too.
        $wavPath = sprintf('%s/claude-%d.wav', $this->directory, ++$this->files);

        return $this->speech->synthesize($answer, $wavPath)
            ->then(fn () => $this->vc->playFile($wavPath));
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
            $this->discord->getLogger()->warning('Could not post in the text channel: ' . $e->getMessage());
        });
    }

    private function nameOf(string $userId): string
    {
        return $this->vc->channel->guild?->members->get('id', $userId)?->displayname
            ?? $this->discord->users->get('id', $userId)?->displayname
            ?? $userId;
    }
}
