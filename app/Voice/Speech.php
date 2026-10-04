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

    /**
     * @param string|null $voice The name of the voice that speaks, when it is not the one in .env: one of voices().
     */
    public static function fromEnv(?string $voice = null): self
    {
        $model = env('PIPER_MODEL', '');

        return new self(
            env('PIPER_BINARY', 'piper'),
            $voice === null ? $model : dirname($model) . "/{$voice}.onnx",
            env('FFMPEG_BINARY', 'ffmpeg'),
        );
    }

    /**
     * The names of the installed voices: those in the same folder as the one in .env.
     * The voice in en_US-lessac-medium.onnx is called "en_US-lessac-medium".
     *
     * @return list<string>
     */
    public static function voices(): array
    {
        // Not glob(): a folder's name can have characters that mean something in a pattern.
        $folder = dirname(env('PIPER_MODEL', ''));
        $files = is_dir($folder) ? scandir($folder) : [];

        return array_values(array_map(
            fn (string $file) => basename($file, '.onnx'),
            array_filter($files, fn (string $file) => str_ends_with($file, '.onnx')),
        ));
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
