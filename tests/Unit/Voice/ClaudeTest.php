<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Support\CommandFailedException;
use App\Voice\Claude;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\FakesClaudeOutput;

use function React\Async\await;
use function React\Async\delay;

final class ClaudeTest extends TestCase
{
    use FakesClaudeOutput;

    private string $log;

    private string $workingDirectory;

    /** Claude's stand-in pauses after its first line until this file exists. */
    private string $resume;

    /** @var list<string> The pieces of its answer Claude handed over while writing it. */
    private array $pieces = [];

    protected function setUp(): void
    {
        $this->log = tempnam(sys_get_temp_dir(), 'fake-claude-');
        $this->workingDirectory = sys_get_temp_dir() . '/claude-test-' . uniqid();
        $this->resume = "{$this->log}.resume";
        putenv("FAKE_CLAUDE_LOG={$this->log}");
        putenv('FAKE_CLAUDE_EXIT');
        putenv('ANTHROPIC_API_KEY=sk-should-not-be-used');
    }

    protected function tearDown(): void
    {
        putenv('FAKE_CLAUDE_LOG');
        putenv('FAKE_CLAUDE_OUTPUT');
        putenv('FAKE_CLAUDE_EXIT');
        putenv('FAKE_CLAUDE_PAUSE');
        putenv('FAKE_CLAUDE_RESUME');
        putenv('FAKE_CLAUDE_DELAY');
        putenv('ANTHROPIC_API_KEY');
        unset($_ENV['CLAUDE_MODEL'], $_ENV['CLAUDE_BINARY'], $_ENV['CLAUDE_LOOKUP_MODEL'], $_ENV['CLAUDE_LOOKUP_ADVISOR']);
        unlink($this->log);
        @unlink($this->resume);
        @rmdir($this->workingDirectory);
    }

    public function testAServersModelReplacesTheOneInEnv(): void
    {
        $this->assertSame('haiku', Claude::fromEnv()->model);

        $_ENV['CLAUDE_MODEL'] = 'opus';

        $this->assertSame('opus', Claude::fromEnv()->model);
        $this->assertSame('sonnet', Claude::fromEnv('sonnet')->model);
        $this->assertSame(['haiku', 'sonnet', 'opus'], Claude::MODELS);
    }

    public function testAsksClaudeCodeWithoutToolsOrAnApiKey(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeStream(" Paris.\n"));

        $answer = await($this->claude()->ask('Alice: Claude, what is the capital of France?'));

        $this->assertSame('Paris.', $answer);

        $log = file_get_contents($this->log);
        $this->assertStringContainsString("cwd={$this->workingDirectory}\n", $log);
        $this->assertStringContainsString("api_key=unset\n", $log);
        $this->assertStringContainsString("stdin=Alice: Claude, what is the capital of France?\n", $log);

        $args = $this->arguments($log);
        $this->assertContains('--print', $args);
        $this->assertSame('stream-json', $this->option($args, '--output-format'));
        $this->assertContains('--verbose', $args, '--print only streams with --verbose.');
        $this->assertContains('--include-partial-messages', $args, 'Without it, the text only comes once it is all written.');
        $this->assertSame('haiku', $this->option($args, '--model'));
        $this->assertSame('', $this->option($args, '--tools'));
        $this->assertNotContains('--allowedTools', $args);
        $this->assertNotContains('--advisor', $args);
        $this->assertContains('--strict-mcp-config', $args);
        $this->assertContains('--no-session-persistence', $args);
        $this->assertStringContainsString('Discord voice call', $this->option($args, '--system-prompt'));
        $this->assertNotContains('--bare', $args, '--bare ignores the subscription login.');
    }

    public function testAsksWithAnotherSystemPromptUnderTheSameSafetyMeasures(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeResult('They agreed to meet on Friday.'));

        $answer = await($this->claude()->ask("Alice: Let's meet on Friday.", 'You summarize voice calls.'));

        $this->assertSame('They agreed to meet on Friday.', $answer);

        $log = file_get_contents($this->log);
        $args = $this->arguments($log);
        $this->assertSame('You summarize voice calls.', $this->option($args, '--system-prompt'));

        // What Claude is asked to do changes nothing about what it can do.
        $this->assertSame('', $this->option($args, '--tools'));
        $this->assertContains('--strict-mcp-config', $args);
        $this->assertContains('--no-session-persistence', $args);
        $this->assertStringContainsString("cwd={$this->workingDirectory}\n", $log);
        $this->assertStringContainsString("api_key=unset\n", $log);
    }

    public function testLooksThingsUpWithWebSearchAndNothingElse(): void
    {
        $_ENV['CLAUDE_BINARY'] = __DIR__ . '/../../Fixtures/fake-claude';
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeResult('PHP 8.5.'));
        $claude = Claude::forLookups(300.0);

        $this->assertSame(['sonnet', 'opus', true, 300.0], [$claude->model, $claude->advisor, $claude->searchesTheWeb, $claude->timeout]);
        $this->assertSame(sys_get_temp_dir() . '/discord-bot-claude', $claude->workingDirectory, 'The directory of every other request.');
        $this->assertSame('PHP 8.5.', await($claude->ask('Task: the latest version of PHP.', 'You look things up.')));

        $log = file_get_contents($this->log);
        $args = $this->arguments($log);
        $this->assertSame('sonnet', $this->option($args, '--model'));
        $this->assertSame('opus', $this->option($args, '--advisor'));

        // Web search is the only tool, and may be used without asking: nobody is there to ask.
        $this->assertSame('WebSearch', $this->option($args, '--tools'));
        $this->assertSame('WebSearch', $this->option($args, '--allowedTools'));
        // What it searches for must not depend on the settings, plugins and hooks of whoever runs the bot.
        $this->assertSame('', $this->option($args, '--setting-sources'));
        $this->assertStringContainsString("arg=--tools\narg=WebSearch\narg=--allowedTools\narg=WebSearch\narg=--setting-sources\narg=\narg=--strict-mcp-config\narg=--no-session-persistence\n", $log);
        $this->assertSame([], array_values(array_filter($args, fn (string $arg) => preg_match('/Fetch|Bash|Read|Write|Edit|mcp/i', $arg) === 1 && ! str_starts_with($arg, '--'))), 'No other tool is named.');
        $this->assertNotContains('--mcp-config', $args);
        $this->assertNotContains('--add-dir', $args);
        $this->assertNotContains('--permission-mode', $args);
        $this->assertNotContains('--dangerously-skip-permissions', $args);
        $this->assertStringContainsString('cwd=' . sys_get_temp_dir() . "/discord-bot-claude\n", $log);
        $this->assertStringContainsString("api_key=unset\n", $log);
    }

    public function testLooksThingsUpWithTheModelAndTheAdvisorInEnv(): void
    {
        $_ENV['CLAUDE_LOOKUP_MODEL'] = 'opus';
        $_ENV['CLAUDE_LOOKUP_ADVISOR'] = ' fable ';

        $claude = Claude::forLookups(300.0);

        $this->assertSame(['opus', 'fable'], [$claude->model, $claude->advisor]);
        $this->assertSame('haiku', Claude::fromEnv()->model, 'Answers keep their own model.');
        $this->assertSame(['', false, 120.0], [Claude::fromEnv()->advisor, Claude::fromEnv()->searchesTheWeb, Claude::fromEnv()->timeout]);
    }

    public function testLooksThingsUpWithoutAnAdvisorWhenItIsEmpty(): void
    {
        $_ENV['CLAUDE_BINARY'] = __DIR__ . '/../../Fixtures/fake-claude';
        $_ENV['CLAUDE_LOOKUP_ADVISOR'] = '';
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeResult('PHP 8.5.'));
        $claude = Claude::forLookups(300.0);

        $this->assertSame('', $claude->advisor);
        await($claude->ask('Task: the latest version of PHP.', 'You look things up.'));

        $args = $this->arguments(file_get_contents($this->log));
        $this->assertNotContains('--advisor', $args);
        $this->assertSame('WebSearch', $this->option($args, '--tools'));
    }

    public function testGivesARequestUpAfterItsTimeout(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeResult('Paris.'));
        putenv('FAKE_CLAUDE_DELAY=1');

        try {
            await((new Claude(__DIR__ . '/../../Fixtures/fake-claude', 'haiku', $this->workingDirectory, timeout: 0.2))->ask('Hello'));
            $this->fail('The question should have timed out.');
        } catch (CommandFailedException $e) {
            $this->assertStringEndsWith('fake-claude timed out after 0.2s', $e->getMessage());
        }
    }

    public function testHandsOverTheAnswerWhileClaudeIsWritingIt(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeStream('It is a quarter', ' past four.', ' Time for tea.'));
        putenv('FAKE_CLAUDE_PAUSE=10');
        putenv("FAKE_CLAUDE_RESUME={$this->resume}");
        $answer = null;

        $asked = $this->claude()->ask('What time is it?', onText: $this->collect(...))->then(function (string $text) use (&$answer) {
            $answer = $text;
        });

        // Claude stops writing after the first piece: it is handed over without waiting for the rest.
        for ($i = 0; $i < 100 && $this->pieces === []; $i++) {
            delay(0.05);
        }
        $this->assertSame(['It is a quarter'], $this->pieces);
        $this->assertNull($answer, 'Claude has not finished yet.');

        touch($this->resume);
        await($asked);

        $this->assertSame(['It is a quarter', ' past four.', ' Time for tea.'], $this->pieces, 'Each piece once, in order.');
        $this->assertSame('It is a quarter past four. Time for tea.', $answer);
    }

    public function testReadsWhatClaudeCodeReallyPrints(): void
    {
        // Recorded from Claude Code 2.1.285 and shortened: besides the answer's text, it has events for hooks,
        // the session, rate limits and Claude's thinking, and the answer once more as a whole message.
        putenv('FAKE_CLAUDE_OUTPUT=' . file_get_contents(__DIR__ . '/../../Fixtures/claude-stream.jsonl'));

        $answer = await($this->claude()->ask('Tell me about the sea.', onText: $this->collect(...)));

        $this->assertSame(
            'Sea waves break endless against stone, swallow light, pull back to try again. Salt air stings. '
                . 'Horizon stretches forever—deep, cold, full of things that move in dark.',
            $answer,
        );
        $this->assertSame($answer, implode('', $this->pieces), 'Only the text of the answer is handed over.');
        $this->assertSame(['Sea', ' waves break endless', ' against'], array_slice($this->pieces, 0, 3));
    }

    public function testIgnoresLinesItDoesNotUnderstand(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . implode("\n", [
            'A warning that is not JSON',
            '"text"',
            '{"type":"stream_event"}',
            '{"type":"stream_event","event":{"type":"content_block_delta","delta":{"type":"thinking_delta","thinking":"Hm."}}}',
            '{"type":"something_new","event":{"delta":{"type":"text_delta","text":"Not the answer."}}}',
            self::claudeStream('Paris.'),
            '',
        ]));

        $this->assertSame('Paris.', await($this->claude()->ask('Hello', onText: $this->collect(...))));
        $this->assertSame(['Paris.'], $this->pieces);
    }

    public function testHandsOverAnAnswerThatWasNotStreamedOnceItIsWhole(): void
    {
        // A Claude Code that doesn't send the text while it is written must not leave the bot silent.
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeResult(" Paris.\n"));

        $this->assertSame('Paris.', await($this->claude()->ask('Hello', onText: $this->collect(...))));
        $this->assertSame(['Paris.'], $this->pieces);
    }

    public function testRejectsWhenClaudeCodeReportsAnError(): void
    {
        // What Claude Code prints when it is not logged in: no text, an error result, and exit code 1.
        putenv('FAKE_CLAUDE_OUTPUT=' . implode("\n", [
            '{"type":"system","subtype":"api_retry","attempt":1,"max_retries":10,"error_status":401,"error":"authentication_failed"}',
            '{"type":"assistant","message":{"model":"<synthetic>","content":[{"type":"text","text":"Not logged in"}]},"error":"authentication_failed"}',
            self::claudeResult('Not logged in · Please run /login', isError: true),
        ]));
        putenv('FAKE_CLAUDE_EXIT=1');

        try {
            await($this->claude()->ask('Hello', onText: $this->collect(...)));
            $this->fail('The question should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Claude Code: Not logged in · Please run /login', $e->getMessage());
        }

        $this->assertSame([], $this->pieces, 'The error is not handed over as if it were the answer.');
    }

    public function testRejectsWhenAnErrorResultComesWithASuccessfulExitCode(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeResult('Usage limit reached', isError: true));

        try {
            await($this->claude()->ask('Hello', onText: $this->collect(...)));
            $this->fail('The question should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Claude Code: Usage limit reached', $e->getMessage());
        }

        $this->assertSame([], $this->pieces);
    }

    public function testRejectsWhenClaudeFailsWhileWritingItsAnswer(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . implode("\n", [
            self::claudeText('It is a quarter past four.'),
            self::claudeText(' Time for'),
            self::claudeResult('API Error: Connection error.', isError: true),
        ]));
        putenv('FAKE_CLAUDE_EXIT=1');

        try {
            await($this->claude()->ask('Hello', onText: $this->collect(...)));
            $this->fail('The question should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Claude Code: API Error: Connection error.', $e->getMessage());
        }

        $this->assertSame(['It is a quarter past four.', ' Time for'], $this->pieces);
    }

    public function testRejectsWithTheProcessErrorWhenThereIsNoResult(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=');
        putenv('FAKE_CLAUDE_EXIT=2');

        $this->expectException(CommandFailedException::class);
        $this->expectExceptionMessageMatches('/fake-claude exited with code 2$/');

        await($this->claude()->ask('Hello'));
    }

    public function testNeverQuotesTheAnswerWhenClaudeCodeCrashesWhileWritingIt(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeText('It is a quarter past four.'));
        putenv('FAKE_CLAUDE_EXIT=3');

        try {
            await($this->claude()->ask('Hello', onText: $this->collect(...)));
            $this->fail('The question should have failed.');
        } catch (CommandFailedException $e) {
            // The error is logged and posted, and logs never contain what Claude answered.
            $this->assertStringEndsWith('fake-claude exited with code 3', $e->getMessage());
            $this->assertSame('', $e->stdout);
        }
    }

    public function testNeverQuotesTheAnswerWhenClaudeCodeFailsAfterGivingIt(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . json_encode(['type' => 'result', 'is_error' => false, 'result' => 'It is a quarter past four.']));
        putenv('FAKE_CLAUDE_EXIT=1');

        try {
            await($this->claude()->ask('Hello'));
            $this->fail('The question should have failed.');
        } catch (CommandFailedException $e) {
            // Its result is the answer, not why it failed, and logs never contain what Claude answered.
            $this->assertStringEndsWith('fake-claude exited with code 1', $e->getMessage());
        }
    }

    public function testRejectsWhenClaudeCodeEndsWithoutAResult(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeText('It is a quarter past four.'));

        try {
            await($this->claude()->ask('Hello'));
            $this->fail('The question should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Unexpected output from Claude Code: no result.', $e->getMessage());
        }
    }

    public function testRejectsAResultWithoutAnAnswer(): void
    {
        // E.g. the result of a run that was cut short, which has no text.
        putenv('FAKE_CLAUDE_OUTPUT={"type":"result","subtype":"error_during_execution","is_error":false}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected output from Claude Code: no result.');

        await($this->claude()->ask('Hello'));
    }

    private function claude(): Claude
    {
        return new Claude(__DIR__ . '/../../Fixtures/fake-claude', 'haiku', $this->workingDirectory);
    }

    private function collect(string $text): void
    {
        $this->pieces[] = $text;
    }

    /**
     * @return list<string>
     */
    private function arguments(string $log): array
    {
        preg_match_all('/^arg=(.*)$/m', $log, $matches);

        return $matches[1];
    }

    /**
     * Returns the value following an option. Multi-line values are cut to their first line by the log format.
     *
     * @param list<string> $args
     */
    private function option(array $args, string $name): string
    {
        $index = array_search($name, $args, true);
        $this->assertNotFalse($index, "{$name} was not passed.");

        return $args[$index + 1];
    }
}
