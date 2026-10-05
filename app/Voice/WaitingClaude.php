<?php

declare(strict_types=1);

namespace App\Voice;

use App\Support\Program;
use App\Support\Shell;
use React\Promise\PromiseInterface;

/**
 * A Claude Code process that is already running and waits for its prompt, so that asking doesn't
 * wait for Claude Code to start: {@see Claude::wait()} starts one. It answers one prompt.
 *
 * It ends once it has answered. Without a prompt, it ends when it is stopped, by itself after some
 * minutes, which Claude Code does, or with the bot, as its stdin is closed then.
 */
final class WaitingClaude
{
    /** Seconds Claude Code has to answer from when it is asked, like one started for the prompt. */
    private const float TIMEOUT = 120.0;

    private readonly Program $program;

    /** What it answers, once it was asked. */
    private ?ClaudeAnswer $answer = null;

    /**
     * @param list<string> $command Claude Code with --input-format stream-json, and what it is otherwise started with.
     * @param array<string, string> $env
     */
    public function __construct(array $command, string $directory, array $env)
    {
        $this->program = Shell::open($command, fn (string $line) => $this->answer?->read($line), cwd: $directory, env: $env);
    }

    /**
     * Gives it its prompt.
     *
     * @param (callable(string $text): void)|null $onText Called with each piece of the answer while Claude is
     *                                                    writing it. Together, the pieces are the whole answer.
     * @return PromiseInterface<string> Claude's answer.
     */
    public function ask(string $prompt, ?callable $onText = null): PromiseInterface
    {
        $this->answer = new ClaudeAnswer($onText === null ? null : $onText(...));

        // With --input-format stream-json a prompt is a message: a JSON object on one line. Nothing follows this
        // one, so stdin is closed, and Claude Code ends once it has answered, like one started for the prompt.
        $message = ['type' => 'user', 'message' => ['role' => 'user', 'content' => $prompt]];
        $this->program->end(json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE) . "\n", self::TIMEOUT);

        return $this->answer->after($this->program->done());
    }

    /**
     * Whether it wrote some of its answer, or said why there is none. When it didn't, as when it ended while
     * it was asked, nothing of an answer was heard, and the prompt can be given to another process.
     */
    public function answered(): bool
    {
        return $this->answer !== null && $this->answer->started();
    }

    /**
     * @return PromiseInterface<mixed> Resolves once it has ended, however it did. It never rejects.
     */
    public function ended(): PromiseInterface
    {
        return $this->program->done()->catch(static fn () => null);
    }

    /**
     * Ends it, when there is nothing left to wait for.
     */
    public function stop(): void
    {
        $this->program->stop();
    }
}
