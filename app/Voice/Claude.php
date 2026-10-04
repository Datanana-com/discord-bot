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
        transcription mistakes and ask for clarification when something is unclear.
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
     * @return PromiseInterface<string> Claude's answer.
     */
    public function ask(string $prompt, string $systemPrompt = self::SYSTEM_PROMPT): PromiseInterface
    {
        // An empty directory keeps Claude Code from picking up a CLAUDE.md or project settings.
        if (! is_dir($this->workingDirectory)) {
            mkdir($this->workingDirectory, 0700, true);
        }

        // ANTHROPIC_API_KEY takes precedence over the subscription login, so it is never passed on.
        $env = getenv();
        unset($env['ANTHROPIC_API_KEY']);

        return Shell::run(
            [
                $this->binary,
                '--print',
                '--output-format', 'json',
                '--model', $this->model,
                '--system-prompt', $systemPrompt,
                // The prompt is built from whatever anyone says in the call,
                // so Claude gets no tools and no MCP servers on this machine.
                '--tools', '',
                '--strict-mcp-config',
                '--no-session-persistence',
            ],
            $prompt,
            $this->workingDirectory,
            $env,
        )->then(self::parse(...))->catch(function (CommandFailedException $e) {
            // Claude Code exits with code 1 on errors (not logged in, usage limit reached, ...)
            // and explains why in its JSON output.
            $result = json_decode($e->stdout, true);

            throw is_string($result['result'] ?? null) ? new RuntimeException('Claude Code: ' . $result['result']) : $e;
        });
    }

    /**
     * Extracts the answer from Claude Code's `--output-format json` result.
     */
    public static function parse(string $json): string
    {
        $result = json_decode($json, true);

        if (! is_array($result) || ! is_string($result['result'] ?? null)) {
            throw new RuntimeException('Unexpected output from Claude Code: ' . mb_substr($json, 0, 200));
        }

        if ($result['is_error'] ?? false) {
            throw new RuntimeException('Claude Code: ' . $result['result']);
        }

        return trim($result['result']);
    }
}
