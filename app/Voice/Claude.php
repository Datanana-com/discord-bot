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

    public static function fromEnv(): self
    {
        return new self(
            env('CLAUDE_BINARY', 'claude'),
            env('CLAUDE_MODEL', 'haiku'),
            sys_get_temp_dir() . '/discord-bot-claude',
        );
    }

    /**
     * @return PromiseInterface<string> Claude's answer.
     */
    public function ask(string $prompt): PromiseInterface
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
                '--system-prompt', self::SYSTEM_PROMPT,
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
