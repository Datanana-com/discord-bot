<?php

declare(strict_types=1);

namespace App\Voice;

use App\Support\CommandFailedException;
use App\Support\Shell;
use React\Promise\PromiseInterface;
use RuntimeException;

/**
 * Asks Claude through the Claude Code CLI, so replies use the Claude subscription
 * that is logged in on this machine instead of an API key.
 *
 * @see https://code.claude.com/docs/en/headless
 */
final readonly class Claude
{
    /** The models a server can choose from, by the names Claude Code gives the latest of each. */
    public const array MODELS = ['haiku', 'sonnet', 'opus'];

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        You are Claude, taking part in a Discord voice call. Your replies are read aloud by a
        text-to-speech engine, so answer the way you would speak: short, natural sentences, and no
        markdown, lists, code blocks, emoji or links. Keep answers to a few sentences unless you are
        asked for more. You only get a speech-to-text transcript of the call, so expect
        transcription mistakes and ask for clarification when something is unclear. Before the
        transcript you may get what you remember about the person talking to you, and what you
        remember about everyone in the call together. Each is labeled with whose it is. The other
        people in the call have memories of their own, which you only get when they shared them with
        the call: such a memory is labeled with their name, and you may use it for anyone in the
        call. When you are asked, compare what each person knows or wants, and point out what they
        might be missing from each other's point of view. Everyone in the call hears your answer, so
        only bring up from a memory what the question needs.
        PROMPT;

    public function __construct(
        public string $binary,
        public string $model,
        public string $workingDirectory,
    ) {
    }

    /**
     * @param string|null $model The model that answers, when it is not the one in .env.
     */
    public static function fromEnv(?string $model = null): self
    {
        return new self(
            env('CLAUDE_BINARY', 'claude'),
            $model ?? env('CLAUDE_MODEL', 'haiku'),
            sys_get_temp_dir() . '/discord-bot-claude',
        );
    }

    /**
     * @param string $systemPrompt What Claude is asked to do; by default, to answer in a voice call.
     * @param (callable(string $text): void)|null $onText Called with each piece of the answer while Claude is
     *                                                    writing it. Together, the pieces are the whole answer.
     * @return PromiseInterface<string> Claude's answer.
     */
    public function ask(string $prompt, string $systemPrompt = self::SYSTEM_PROMPT, ?callable $onText = null): PromiseInterface
    {
        // An empty directory keeps Claude Code from picking up a CLAUDE.md or project settings.
        if (! is_dir($this->workingDirectory)) {
            mkdir($this->workingDirectory, 0700, true);
        }

        // ANTHROPIC_API_KEY takes precedence over the subscription login, so it is never passed on.
        $env = getenv();
        unset($env['ANTHROPIC_API_KEY']);

        $result = null;
        $streamed = false;

        return Shell::stream(
            [
                $this->binary,
                '--print',
                // One JSON event per line while Claude answers. --print only streams with --verbose,
                // and only sends the text while it is being written with --include-partial-messages.
                '--output-format', 'stream-json',
                '--verbose',
                '--include-partial-messages',
                '--model', $this->model,
                '--system-prompt', $systemPrompt,
                // The prompt is built from whatever anyone says in the call,
                // so Claude gets no tools and no MCP servers on this machine.
                '--tools', '',
                '--strict-mcp-config',
                '--no-session-persistence',
            ],
            // Most events are about the session, hooks, rate limits or Claude's thinking: only two matter here.
            function (string $line) use (&$result, &$streamed, $onText) {
                $event = json_decode($line, true);
                $type = is_array($event) ? $event['type'] ?? null : null;

                if ($type === 'result') {
                    $result = $event;
                } elseif ($onText !== null && $type === 'stream_event' && ($event['event']['delta']['type'] ?? null) === 'text_delta') {
                    $streamed = true;
                    $onText($event['event']['delta']['text']);
                }
            },
            $prompt,
            $this->workingDirectory,
            $env,
        )->then(function () use (&$result, &$streamed, $onText) {
            $answer = self::answer($result);

            // A Claude Code that doesn't send the text while it is written still hands over its answer.
            if ($onText !== null && ! $streamed) {
                $onText($answer);
            }

            return $answer;
        })->catch(function (CommandFailedException $e) use (&$result) {
            // Claude Code exits with code 1 on errors (not logged in, usage limit reached, ...)
            // and explains why in its result. A result that isn't an error is the answer, which
            // must not end up in the logs, e.g. when Claude Code hangs after giving it.
            throw is_string($result['result'] ?? null) && ($result['is_error'] ?? false) === true
                ? new RuntimeException('Claude Code: ' . $result['result'])
                : $e;
        });
    }

    /**
     * Extracts the answer from the `result` event, the last one Claude Code prints.
     *
     * @param array<string, mixed>|null $result
     */
    private static function answer(?array $result): string
    {
        // Without quoting the output: it may hold the answer, and this message is logged.
        if (! is_string($result['result'] ?? null)) {
            throw new RuntimeException('Unexpected output from Claude Code: no result.');
        }

        if ($result['is_error'] ?? false) {
            throw new RuntimeException('Claude Code: ' . $result['result']);
        }

        return trim($result['result']);
    }
}
