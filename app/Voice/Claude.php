<?php

declare(strict_types=1);

namespace App\Voice;

use App\Support\Shell;
use React\Promise\PromiseInterface;

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
     * @param bool $thinks Whether Claude may think before it answers. In a call it doesn't: thinking takes
     *                     seconds before the first word of a one-line answer.
     * @return PromiseInterface<string> Claude's answer.
     */
    public function ask(string $prompt, string $systemPrompt = self::SYSTEM_PROMPT, ?callable $onText = null, bool $thinks = true): PromiseInterface
    {
        $answer = new ClaudeAnswer($onText === null ? null : $onText(...));

        return $answer->after(Shell::stream(
            $this->command($systemPrompt),
            $answer->read(...),
            $prompt,
            $this->directory(),
            $this->environment($thinks),
        ));
    }

    /**
     * Starts a Claude Code process that waits for its prompt, so that asking it doesn't wait for Claude Code
     * to start. It is run like one that ask() starts, and answers one prompt.
     *
     * @param string $systemPrompt What Claude is asked to do; by default, to answer in a voice call.
     * @param bool $thinks Whether Claude may think before it answers.
     */
    public function wait(string $systemPrompt = self::SYSTEM_PROMPT, bool $thinks = true): WaitingClaude
    {
        return new WaitingClaude(
            [...$this->command($systemPrompt), '--input-format', 'stream-json'],
            $this->directory(),
            $this->environment($thinks),
        );
    }

    /**
     * The command that starts Claude Code to answer one prompt, which it reads from its stdin.
     *
     * @return list<string>
     */
    private function command(string $systemPrompt): array
    {
        return [
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
            // Nor the settings of the user the bot runs as: their plugins, skills and hooks would be loaded
            // for every prompt, which takes seconds, and a plugin can change how Claude answers.
            '--setting-sources', '',
        ];
    }

    /**
     * The environment Claude Code runs in.
     *
     * @return array<string, string>
     */
    private function environment(bool $thinks): array
    {
        // ANTHROPIC_API_KEY takes precedence over the subscription login, so it is never passed on.
        $env = getenv();
        unset($env['ANTHROPIC_API_KEY']);

        // Claude Code is ready sooner when it doesn't look for updates, nor sends what it can do without.
        $env['CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC'] = '1';
        $env['DISABLE_AUTOUPDATER'] = '1';

        if (! $thinks) {
            $env['MAX_THINKING_TOKENS'] = '0';
        }

        return $env;
    }

    /**
     * The directory Claude Code runs in: an empty one keeps it from picking up a CLAUDE.md or project settings.
     */
    private function directory(): string
    {
        if (! is_dir($this->workingDirectory)) {
            mkdir($this->workingDirectory, 0700, true);
        }

        return $this->workingDirectory;
    }
}
