<?php

declare(strict_types=1);

namespace App\Voice;

use App\Support\Shell;
use React\Promise\PromiseInterface;

/**
 * Speech-to-text through a local whisper.cpp build.
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

    public function __construct(
        public string $binary,
        public string $model,
        public string $language,
        public float $minimumTimeout = self::MINIMUM_TIMEOUT,
    ) {
    }

    /**
     * @param string|null $language The language spoken, when it is not the one in .env.
     */
    public static function fromEnv(?string $language = null): self
    {
        return new self(
            env('WHISPER_BINARY', 'whisper-cli'),
            env('WHISPER_MODEL', ''),
            $language ?? env('WHISPER_LANGUAGE', 'auto'),
        );
    }

    /**
     * Transcribes a WAV file. whisper.cpp resamples the audio itself,
     * so Discord's 48 kHz stereo recordings can be passed as they are.
     *
     * @param float $seconds How long the audio is, when it is known: whisper.cpp is given {@see SECONDS_PER_SECOND_OF_AUDIO}
     *                       for each of them, and at least the minimum timeout, before it is killed.
     * @return PromiseInterface<string> The spoken text, or an empty string when nothing was said.
     */
    public function transcribe(string $wavPath, float $seconds = 0.0): PromiseInterface
    {
        return Shell::run([
            $this->binary,
            '--model', $this->model,
            '--language', $this->language,
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
