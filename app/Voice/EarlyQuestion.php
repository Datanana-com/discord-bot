<?php

declare(strict_types=1);

namespace App\Voice;

use Closure;
use React\Promise\PromiseInterface;

/**
 * A question Claude is asked while its person is still pausing, to be used when the pause turns out to be the
 * end of the sentence: see {@see VoiceSession::askEarly()}.
 *
 * Nothing of its answer is let out while it waits: what Claude writes is held, and handed on once the
 * sentence has ended and its prompt is found to be the one that was sent. Otherwise it is dropped, and the
 * Claude Code process it was asked of is ended.
 */
final class EarlyQuestion
{
    /** @var list<array{bool, mixed}> What Claude wrote before it was used: true for a piece of the answer, false for its timing. */
    private array $held = [];

    private ?Closure $onText = null;

    private ?Closure $onStarted = null;

    /** @var array{PromiseInterface<string|null>, PromiseInterface<null>, Closure(): void}|null */
    private ?array $asked = null;

    /** Whether it was used or dropped: it is then over. */
    private bool $over = false;

    /** When it was asked, by the clock that only goes forward, in nanoseconds. */
    private readonly int $since;

    /**
     * @param string $userId Whose sentence it is.
     * @param string $prompt What Claude was asked.
     * @param string $stamp The time of the transcript line that holds the sentence, which is part of the prompt:
     *        the line is added to the transcript with the same time when the sentence ends.
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $prompt,
        public readonly string $stamp,
    ) {
        $this->since = hrtime(true);
    }

    /**
     * Called with each piece of the answer as Claude writes it.
     */
    public function text(string $text): void
    {
        $this->onText === null ? $this->held[] = [true, $text] : ($this->onText)($text);
    }

    /**
     * Called once, before the first piece, with how long Claude took to start answering.
     *
     * @param array{ms: int, init_ms: ?int, retries: int, rate_limits: int} $timing
     */
    public function started(array $timing): void
    {
        $this->onStarted === null ? $this->held[] = [false, $timing] : ($this->onStarted)($timing);
    }

    /**
     * Sets what it was asked with: the answer, what resolves when it is ended, and what ends it.
     *
     * @param array{PromiseInterface<string|null>, PromiseInterface<null>, Closure(): void} $asked
     */
    public function asked(array $asked): void
    {
        $this->asked = $asked;
        // Nobody listens to the answer of one that is dropped, and a rejection nobody handles is reported.
        $asked[0]->then(null, static fn () => null);
    }

    /**
     * Whether it was asked what is asked now, byte for byte, and is neither used nor dropped: the answer is then
     * the one that asking now would have got, whatever changed in between.
     */
    public function matches(string $prompt): bool
    {
        return ! $this->over && $this->prompt === $prompt;
    }

    /**
     * Takes it up: what Claude wrote so far is handed on, in order, and the rest as it is written.
     *
     * @param callable(string $text): void $onText
     * @param (callable(array{ms: int, init_ms: ?int, retries: int, rate_limits: int} $timing): void)|null $onStarted
     * @return array{PromiseInterface<string|null>, PromiseInterface<null>, Closure(): void} See {@see asked()}.
     */
    public function adopt(callable $onText, ?callable $onStarted): array
    {
        $this->over = true;
        $this->onText = $onText(...);
        $this->onStarted = $onStarted === null ? static fn () => null : $onStarted(...);

        foreach ($this->held as [$isText, $what]) {
            $isText ? ($this->onText)($what) : ($this->onStarted)($what);
        }


        return $this->asked;
    }

    /**
     * Throws it away: Claude Code is ended, and nothing it wrote is used.
     *
     * @return bool Whether it was still there to drop: false when it was used or dropped before.
     */
    public function drop(): bool
    {
        if ($this->over) {
            return false;
        }

        $this->over = true;
        $this->held = [];
        $this->asked[2]();

        return true;
    }

    /**
     * How long ago it was asked, in milliseconds.
     */
    public function ageMs(): int
    {
        return (int) round((hrtime(true) - $this->since) / 1e6);
    }
}
