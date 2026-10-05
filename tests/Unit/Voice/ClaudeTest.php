<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Support\CommandFailedException;
use App\Voice\Claude;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function React\Async\await;

final class ClaudeTest extends TestCase
{
    private string $log;

    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->log = tempnam(sys_get_temp_dir(), 'fake-claude-');
        $this->workingDirectory = sys_get_temp_dir() . '/claude-test-' . uniqid();
        putenv("FAKE_CLAUDE_LOG={$this->log}");
        putenv('FAKE_CLAUDE_EXIT');
        putenv('ANTHROPIC_API_KEY=sk-should-not-be-used');
    }

    protected function tearDown(): void
    {
        putenv('FAKE_CLAUDE_LOG');
        putenv('FAKE_CLAUDE_OUTPUT');
        putenv('ANTHROPIC_API_KEY');
        unset($_ENV['CLAUDE_MODEL']);
        unlink($this->log);
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
        putenv('FAKE_CLAUDE_OUTPUT=' . json_encode(['type' => 'result', 'is_error' => false, 'result' => " Paris.\n"]));

        $answer = await($this->claude()->ask('Alice: Claude, what is the capital of France?'));

        $this->assertSame('Paris.', $answer);

        $log = file_get_contents($this->log);
        $this->assertStringContainsString("cwd={$this->workingDirectory}\n", $log);
        $this->assertStringContainsString("api_key=unset\n", $log);
        $this->assertStringContainsString("stdin=Alice: Claude, what is the capital of France?\n", $log);

        $args = $this->arguments($log);
        $this->assertContains('--print', $args);
        $this->assertSame('json', $this->option($args, '--output-format'));
        $this->assertSame('haiku', $this->option($args, '--model'));
        $this->assertSame('', $this->option($args, '--tools'));
        $this->assertContains('--strict-mcp-config', $args);
        $this->assertContains('--no-session-persistence', $args);
        $this->assertStringContainsString('Discord voice call', $this->option($args, '--system-prompt'));
        $this->assertNotContains('--bare', $args, '--bare ignores the subscription login.');
    }

    public function testAsksWithAnotherSystemPromptUnderTheSameSafetyMeasures(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . json_encode(['type' => 'result', 'is_error' => false, 'result' => 'They agreed to meet on Friday.']));

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

    public function testRejectsWhenClaudeCodeReportsAnError(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . json_encode(['is_error' => true, 'result' => 'Not logged in · Please run /login']));
        putenv('FAKE_CLAUDE_EXIT=1');

        try {
            await($this->claude()->ask('Hello'));
            $this->fail('The question should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Claude Code: Not logged in · Please run /login', $e->getMessage());
        }
    }

    public function testRejectsWithTheProcessErrorWhenThereIsNoJsonOutput(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=');
        putenv('FAKE_CLAUDE_EXIT=2');

        $this->expectException(CommandFailedException::class);
        $this->expectExceptionMessageMatches('/fake-claude exited with code 2/');

        await($this->claude()->ask('Hello'));
    }

    public function testParseRejectsUnexpectedOutput(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected output from Claude Code');

        Claude::parse('not json');
    }

    public function testParseRejectsErrorResults(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Claude Code: Usage limit reached');

        Claude::parse(json_encode(['is_error' => true, 'result' => 'Usage limit reached']));
    }

    private function claude(): Claude
    {
        return new Claude(__DIR__ . '/../../Fixtures/fake-claude', 'haiku', $this->workingDirectory);
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
