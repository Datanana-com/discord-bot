<?php

declare(strict_types=1);

namespace App\Voice;

use App\Support\Program;
use App\Support\Shell;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * An ffmpeg that encodes the speech of one sentence: it reads the voice's WAV file on its stdin, and writes the
 * sentence's Ogg Opus stream to its stdout, which goes to the {@see Sentence} as it comes.
 *
 * It is started before there is a sentence for it, and waits: starting an ffmpeg takes 60 to 80 ms, which the
 * first sound of an answer would otherwise wait for. {@see Speech} keeps one waiting for the whole call, and
 * starts the next one when a sentence takes it.
 */
final class Encoder
{
    private readonly Program $ffmpeg;

    /** The sentence it encodes, once it has one. */
    private ?Sentence $sentence = null;

    /**
     * @param list<string> $command The ffmpeg command, which reads stdin and writes stdout.
     */
    public function __construct(array $command)
    {
        $this->ffmpeg = Shell::open($command, onBytes: fn (string $bytes) => $this->sentence?->write($bytes));
    }

    /**
     * Whether it still waits for a sentence, or works on one. It doesn't once it ended, as when it could not
     * be started.
     */
    public function isRunning(): bool
    {
        return $this->ffmpeg->isRunning();
    }

    /**
     * Gives it the speech of the one sentence it encodes. The sentence gets its stream as it comes, is whole
     * when the ffmpeg has ended, and fails when that fails.
     *
     * @param string $wav The bytes of the voice's WAV file.
     * @param float $timeout Seconds it has, before it is stopped.
     */
    public function encode(string $wav, Sentence $sentence, float $timeout): void
    {
        $this->sentence = $sentence;
        $this->ffmpeg->end($wav, $timeout);
        $this->ffmpeg->done()->then(static fn () => $sentence->end(), static fn (Throwable $e) => $sentence->fail($e));
    }

    /**
     * Stops it now, whether it waits or works.
     */
    public function stop(): void
    {
        $this->ffmpeg->stop();
    }

    /**
     * @return PromiseInterface<null> Resolves when it has ended, and rejects when it failed or was stopped:
     *                                see {@see Program::done()}.
     */
    public function done(): PromiseInterface
    {
        return $this->ffmpeg->done();
    }
}
