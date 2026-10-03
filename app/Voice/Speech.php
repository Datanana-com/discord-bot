<?php

declare(strict_types=1);

namespace App\Voice;

use App\Support\Shell;
use React\Promise\PromiseInterface;

/**
 * Text-to-speech through a local Piper install.
 *
 * @see https://github.com/OHF-Voice/piper1-gpl
 */
final readonly class Speech
{
    public function __construct(
        public string $binary,
        public string $model,
        public string $ffmpeg = 'ffmpeg',
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            env('PIPER_BINARY', 'piper'),
            env('PIPER_MODEL', ''),
            env('FFMPEG_BINARY', 'ffmpeg'),
        );
    }

    /**
     * Speaks the text into an Ogg Opus file.
     *
     * Piper writes WAV files, but the voice library plays files through ffmpeg with
     * -fflags +nobuffer, which drops whatever ffmpeg reads while probing the file: all of
     * a short Piper WAV, yet only the first 20 ms of an Ogg Opus file. So Piper's WAV is
     * converted.
     *
     * @return PromiseInterface<string> The path of the written file.
     */
    public function synthesize(string $text, string $oggPath): PromiseInterface
    {
        $piperPath = "{$oggPath}.piper.wav";

        return Shell::run([$this->binary, '--model', $this->model, '--output_file', $piperPath], $text)
            ->then(fn () => Shell::run([$this->ffmpeg, '-loglevel', 'error', '-y', '-i', $piperPath, '-c:a', 'libopus', $oggPath]))
            ->finally(fn () => is_file($piperPath) && unlink($piperPath))
            ->then(fn () => $oggPath);
    }
}
