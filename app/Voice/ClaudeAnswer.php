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

    /** When the prompt was given: what the times of {@see timing()} count from. */
    private readonly float $askedAt;

    /** When Claude Code said it had finished starting (its `init` event), when it did. */
    private ?float $initAt = null;

    /** How often Claude Code said it was trying a request again. */
    private int $retries = 0;

    /** How often Claude Code said something about a rate limit. */
    private int $rateLimits = 0;

    /**
     * @param (Closure(string $text): void)|null $onText Called with each piece of the answer while Claude is
     *                                                   writing it. Together, the pieces are the whole answer.
     * @param (Closure(array{ms: int, init_ms: ?int, retries: int, rate_limits: int} $timing): void)|null $onStarted
     *        Called once, just before the first piece of the answer is handed over, with how long that took: see {@see timing()}.
     */
    public function __construct(private readonly ?Closure $onText, private readonly ?Closure $onStarted = null)
    {
        $this->askedAt = microtime(true);
    }

    /**
     * Reads a line Claude Code printed. Most events are about the session, hooks or Claude's thinking: the
     * answer is in two of them, and three others say where the time to it went.
     */
    public function read(string $line): void
    {
        $event = json_decode($line, true);
        $type = is_array($event) ? $event['type'] ?? null : null;

        if ($type === 'result') {
            $this->result = $event;
        } elseif ($type === 'system' && ($event['subtype'] ?? null) === 'init') {
            $this->initAt ??= microtime(true);
        } elseif ($type === 'system' && ($event['subtype'] ?? null) === 'api_retry') {
            $this->retries++;
        } elseif ($type === 'rate_limit_event') {
            $this->rateLimits++;
        } elseif ($this->onText !== null && $type === 'stream_event' && ($event['event']['delta']['type'] ?? null) === 'text_delta') {
            $this->handOver($event['event']['delta']['text']);
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
     * How long Claude took to start answering, counted from the prompt: to now (`ms`), to Claude Code saying it
     * had finished starting (`init_ms`, null when it never said so), and how often it tried a request again or
     * was told about a rate limit meanwhile. Numbers only: nothing of the answer.
     *
     * @return array{ms: int, init_ms: ?int, retries: int, rate_limits: int}
     */
    public function timing(): array
    {
        return [
            'ms' => (int) round((microtime(true) - $this->askedAt) * 1000),
            'init_ms' => $this->initAt === null ? null : (int) round(($this->initAt - $this->askedAt) * 1000),
            'retries' => $this->retries,
            'rate_limits' => $this->rateLimits,
        ];
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
                $this->handOver($answer);
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
     * Hands a piece of the answer over, and first says that the answer started when it is the first piece.
     */
    private function handOver(string $text): void
    {
        if (! $this->streamed) {
            $this->streamed = true;

            if ($this->onStarted !== null) {
                ($this->onStarted)($this->timing());
            }
        }

        ($this->onText)($text);
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
