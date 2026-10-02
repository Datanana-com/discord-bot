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
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            env('PIPER_BINARY', 'piper'),
            env('PIPER_MODEL', ''),
        );
    }

    /**
     * Speaks the text into a WAV file.
     *
     * @return PromiseInterface<string> The path of the written file.
     */
    public function synthesize(string $text, string $wavPath): PromiseInterface
    {
        return Shell::run([$this->binary, '--model', $this->model, '--output_file', $wavPath], $text)
            ->then(fn () => $wavPath);
    }
}
