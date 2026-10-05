<?php

declare(strict_types=1);

namespace App\Voice;

use App\Support\CommandFailedException;
use Closure;
use React\Promise\PromiseInterface;
use RuntimeException;

/**
 * An answer Claude Code is writing: what it prints with --output-format stream-json, one JSON event per line.
 */
final class ClaudeAnswer
{
    /** @var array<string, mixed>|null The `result` event, the last one Claude Code prints. */
    private ?array $result = null;

    /** Whether text of the answer was handed over while Claude was writing it. */
    private bool $streamed = false;

    /**
     * @param (Closure(string $text): void)|null $onText Called with each piece of the answer while Claude is
     *                                                   writing it. Together, the pieces are the whole answer.
     */
    public function __construct(private readonly ?Closure $onText)
    {
    }

    /**
     * Reads a line Claude Code printed. Most events are about the session, hooks, rate limits or Claude's
     * thinking: only two matter here.
     */
    public function read(string $line): void
    {
        $event = json_decode($line, true);
        $type = is_array($event) ? $event['type'] ?? null : null;

        if ($type === 'result') {
            $this->result = $event;
        } elseif ($this->onText !== null && $type === 'stream_event' && ($event['event']['delta']['type'] ?? null) === 'text_delta') {
            $this->streamed = true;
            ($this->onText)($event['event']['delta']['text']);
        }
    }

    /**
     * Whether Claude Code got as far as writing some of the answer, or saying why there is none.
     */
    public function started(): bool
    {
        return $this->streamed || $this->result !== null;
    }

    /**
     * @param PromiseInterface<mixed> $ended Resolves when Claude Code has ended, and rejects with a
     *                                       {@see CommandFailedException} when it failed.
     * @return PromiseInterface<string> Claude's answer.
     */
    public function after(PromiseInterface $ended): PromiseInterface
    {
        return $ended->then(function () {
            $answer = $this->answer();

            // A Claude Code that doesn't send the text while it is written still hands over its answer.
            if ($this->onText !== null && ! $this->streamed) {
                ($this->onText)($answer);
            }

            return $answer;
        })->catch(function (CommandFailedException $e) {
            // Claude Code exits with code 1 on errors (not logged in, usage limit reached, ...)
            // and explains why in its result. A result that isn't an error is the answer, which
            // must not end up in the logs, e.g. when Claude Code hangs after giving it.
            throw is_string($this->result['result'] ?? null) && ($this->result['is_error'] ?? false) === true
                ? new RuntimeException('Claude Code: ' . $this->result['result'])
                : $e;
        });
    }

    /**
     * Extracts the answer from the `result` event.
     */
    private function answer(): string
    {
        // Without quoting the output: it may hold the answer, and this message is logged.
        if (! is_string($this->result['result'] ?? null)) {
            throw new RuntimeException('Unexpected output from Claude Code: no result.');
        }

        if ($this->result['is_error'] ?? false) {
            throw new RuntimeException('Claude Code: ' . $this->result['result']);
        }

        return trim($this->result['result']);
    }
}
