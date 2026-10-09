<?php

declare(strict_types=1);

namespace App\Voice;

use App\Support\Shell;
use Closure;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

use function React\Promise\reject;

/**
 * Speech-to-text through a local whisper.cpp build: the whisper-server that a call keeps running, when there is
 * one that is ready, and whisper-cli otherwise.
 *
 * @see https://github.com/ggml-org/whisper.cpp
 */
final readonly class Transcriber
{
    /**
     * The codes of the languages whisper transcribes: g_lang in whisper.cpp's src/whisper.cpp, as of v1.9.4.
     * Models whose name ends in ".en" only know English.
     */
    public const array LANGUAGES = [
        'af', 'am', 'ar', 'as', 'az', 'ba', 'be', 'bg', 'bn', 'bo', 'br', 'bs', 'ca', 'cs', 'cy', 'da', 'de', 'el', 'en', 'es',
        'et', 'eu', 'fa', 'fi', 'fo', 'fr', 'gl', 'gu', 'ha', 'haw', 'he', 'hi', 'hr', 'ht', 'hu', 'hy', 'id', 'is', 'it', 'ja',
        'jw', 'ka', 'kk', 'km', 'kn', 'ko', 'la', 'lb', 'ln', 'lo', 'lt', 'lv', 'mg', 'mi', 'mk', 'ml', 'mn', 'mr', 'ms', 'mt',
        'my', 'ne', 'nl', 'nn', 'no', 'oc', 'pa', 'pl', 'ps', 'pt', 'ro', 'ru', 'sa', 'sd', 'si', 'sk', 'sl', 'sn', 'so', 'sq',
        'sr', 'su', 'sv', 'sw', 'ta', 'te', 'tg', 'th', 'tk', 'tl', 'tr', 'tt', 'uk', 'ur', 'uz', 'vi', 'yi', 'yo', 'yue', 'zh',
    ];

    /** How long whisper.cpp may take for each second of audio: a big model on a slow CPU can be slower than the speech itself. */
    public const float SECONDS_PER_SECOND_OF_AUDIO = 3.0;

    /** How long whisper.cpp may take at least, whatever the length of the audio: it has to load its model first. */
    public const float MINIMUM_TIMEOUT = 120.0;

    /**
     * The longest recording the server transcribes: what someone can say in a call at a time. It does one at a time, and
     * one of five minutes, like a voice message in a DM, would keep every call waiting for the seconds that takes.
     */
    public const float SERVER_MAX_SECONDS = 30.0;

    /**
     * @param int|null $threads How many threads whisper uses, or null for as many as it takes by itself: 4, or
     *                          as many as the CPU has when that is fewer.
     */
    public function __construct(
        public string $binary,
        public string $model,
        public string $language,
        public string $prompt = '',
        public float $minimumTimeout = self::MINIMUM_TIMEOUT,
        public ?int $threads = null,
        public ?WhisperServer $server = null,
    ) {
    }

    /**
     * @param string|null $language The language spoken, when it is not the one in .env.
     */
    public static function fromEnv(?string $language = null): self
    {
        // Anything but a whole number of threads, 1 or more, leaves it to whisper.
        $threads = filter_var(env('WHISPER_THREADS', ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $threads = $threads === false ? null : $threads;
        $model = env('WHISPER_MODEL', '');

        return new self(
            env('WHISPER_BINARY', 'whisper-cli'),
            $model,
            $language ?? env('WHISPER_LANGUAGE', 'auto'),
            trim(env('WHISPER_PROMPT', '')),
            threads: $threads,
            server: WhisperServer::fromEnv($model, $threads),
        );
    }

    /**
     * Transcribes a WAV file. whisper.cpp resamples the audio itself,
     * so Discord's 48 kHz stereo recordings can be passed as they are.
     *
     * The server transcribes it when a call has one that is ready, unless the recording is longer than
     * {@see SERVER_MAX_SECONDS}. When the server fails, whisper-cli does.
     *
     * @param float $seconds How long the audio is, when it is known: whisper.cpp is given {@see SECONDS_PER_SECOND_OF_AUDIO}
     *                       for each of them, and at least the minimum timeout, before it is killed.
     * @param (Closure(string $level, string $message): void)|null $log Where to say that the server failed.
     * @return PromiseInterface<string> The spoken text, or an empty string when nothing was said.
     */
    public function transcribe(string $wavPath, float $seconds = 0.0, ?Closure $log = null): PromiseInterface
    {
        if ($this->server === null || ! $this->server->isReady() || $seconds > self::SERVER_MAX_SECONDS) {
            return $this->withWhisperCli($wavPath, $seconds);
        }

        return $this->server->transcribe($wavPath, $this->language, $this->prompt, $seconds)->then(
            self::clean(...),
            function (Throwable $e) use ($wavPath, $seconds, $log) {
                // The utterance is not lost with the server: it is only slower.
                $log?->__invoke('warning', 'The whisper server could not transcribe, whisper-cli does: ' . $e->getMessage());

                return $this->withWhisperCli($wavPath, $seconds);
            },
        );
    }

    /**
     * Transcribes a copy of what is still being said, which may be thrown away: with the server only. whisper-cli
     * loads its model for every file, so a copy that is thrown away would cost as much as one that is used.
     *
     * @return PromiseInterface<string> The spoken text, as {@see transcribe()}. Rejects when there is no server that is
     *                                  ready, or it fails: the recording is then transcribed once it is over.
     */
    public function transcribeEarly(string $wavPath, float $seconds = 0.0): PromiseInterface
    {
        if ($this->server === null || ! $this->server->isReady()) {
            return reject(new RuntimeException('There is no whisper server to transcribe with early.'));
        }

        return $this->server->transcribe($wavPath, $this->language, $this->prompt, $seconds)->then(self::clean(...));
    }

    /**
     * @return PromiseInterface<string>
     */
    private function withWhisperCli(string $wavPath, float $seconds): PromiseInterface
    {
        return Shell::run([
            $this->binary,
            '--model', $this->model,
            '--language', $this->language,
            ...($this->threads === null ? [] : ['--threads', (string) $this->threads]),
            ...($this->prompt === '' ? [] : ['--prompt', $this->prompt]),
            '--no-timestamps',
            '--no-prints',
            '--file', $wavPath,
        ], timeout: max($this->minimumTimeout, self::SECONDS_PER_SECOND_OF_AUDIO * $seconds))->then(self::clean(...));
    }

    /**
     * Removes whisper's annotations for non-speech audio, e.g. "[BLANK_AUDIO]" or "(keyboard clicking)".
     */
    public static function clean(string $output): string
    {
        return $output
            |> (fn (string $text) => preg_replace('/\[[^\]]*\]|\([^)]*\)/', ' ', $text))
            |> (fn (string $text) => preg_replace('/\s+/', ' ', $text))
            |> trim(...);
    }
}
