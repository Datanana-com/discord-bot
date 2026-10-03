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
    public function __construct(
        public string $binary,
        public string $model,
        public string $language,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            env('WHISPER_BINARY', 'whisper-cli'),
            env('WHISPER_MODEL', ''),
            env('WHISPER_LANGUAGE', 'auto'),
        );
    }

    /**
     * Transcribes a WAV file. whisper.cpp resamples the audio itself,
     * so Discord's 48 kHz stereo recordings can be passed as they are.
     *
     * @return PromiseInterface<string> The spoken text, or an empty string when nothing was said.
     */
    public function transcribe(string $wavPath): PromiseInterface
    {
        return Shell::run([
            $this->binary,
            '--model', $this->model,
            '--language', $this->language,
            '--no-timestamps',
            '--no-prints',
            '--file', $wavPath,
        ])->then(self::clean(...));
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
