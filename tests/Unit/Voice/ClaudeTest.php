<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Support\CommandFailedException;
use App\Voice\Claude;
use App\Voice\WaitingClaude;
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

    /** How much Claude thinks, when whoever runs the tests has set it. */
    private string|false $thinking;

    /** @var list<WaitingClaude> The processes a test started to wait for a prompt. */
    private array $waiting = [];

    protected function setUp(): void
    {
        $this->thinking = getenv('MAX_THINKING_TOKENS');
        putenv('MAX_THINKING_TOKENS');
        $this->log = tempnam(sys_get_temp_dir(), 'fake-claude-');
        $this->workingDirectory = sys_get_temp_dir() . '/claude-test-' . uniqid();
        $this->resume = "{$this->log}.resume";
        putenv("FAKE_CLAUDE_LOG={$this->log}");
        putenv('FAKE_CLAUDE_EXIT');
        putenv('ANTHROPIC_API_KEY=sk-should-not-be-used');
    }

    protected function tearDown(): void
    {
        foreach ($this->waiting as $waiting) {
            $waiting->stop();
            await($waiting->ended());
        }

        putenv('FAKE_CLAUDE_WAITING');
        putenv('FAKE_CLAUDE_WAITING_FAILS');
        putenv('FAKE_CLAUDE_WAITING_LEAVES');
        putenv('FAKE_CLAUDE_WAITING_GREETS');
        @unlink("{$this->log}.waiting");
        putenv('FAKE_CLAUDE_LOG');
        putenv('FAKE_CLAUDE_OUTPUT');
        putenv('FAKE_CLAUDE_EXIT');
        putenv('FAKE_CLAUDE_PAUSE');
        putenv('FAKE_CLAUDE_RESUME');
        putenv('ANTHROPIC_API_KEY');
        putenv($this->thinking === false ? 'MAX_THINKING_TOKENS' : "MAX_THINKING_TOKENS={$this->thinking}");
        unset($_ENV['CLAUDE_MODEL']);
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
        $this->assertContains('--strict-mcp-config', $args);
        $this->assertContains('--no-session-persistence', $args);
        $this->assertStringContainsString('Discord voice call', $this->option($args, '--system-prompt'));
        $this->assertNotContains('--bare', $args, '--bare ignores the subscription login.');
    }

    public function testNeverLoadsTheSettingsOfTheUserTheBotRunsAs(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeResult('Paris.'));

        await($this->claude()->ask('Hello', 'You summarize voice calls.'));

        // Their plugins, skills and hooks would be loaded for every prompt, and can change how Claude answers.
        $log = file_get_contents($this->log);
        $this->assertSame('', $this->option($this->arguments($log), '--setting-sources'));

        // Claude Code is ready sooner without its update check and the requests it can do without.
        $this->assertStringContainsString("nonessential_traffic=1\n", $log);
        $this->assertStringContainsString("autoupdater=1\n", $log);
    }

    public function testThinksAsMuchAsItIsSetToUnlessToldNotTo(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeResult('Paris.'));

        await($this->claude()->ask('Hello'));
        $this->assertStringContainsString("thinking=unset\n", file_get_contents($this->log));

        putenv('MAX_THINKING_TOKENS=4000');
        await($this->claude()->ask('Hello'));
        $this->assertStringContainsString("thinking=4000\n", file_get_contents($this->log));

        // An answer in a call can't wait for it.
        await($this->claude()->ask('Hello', thinks: false));
        $log = file_get_contents($this->log);
        $this->assertStringContainsString("thinking=0\n", $log);
        $this->assertSame('', $this->option($this->arguments($log), '--setting-sources'));
        $this->assertStringContainsString("nonessential_traffic=1\nautoupdater=1\n", $log);
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

    public function testAWaitingProcessIsStartedBeforeItsPromptAndRunLikeAnyOther(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeStream('It is a quarter', ' past four.'));
        putenv("FAKE_CLAUDE_WAITING={$this->log}.waiting");

        $waiting = $this->waiting($this->claude()->wait());

        // It is running, and was given nothing yet.
        $pid = $this->waitingPid();
        $this->assertTrue(posix_kill($pid, 0));
        $this->assertSame('', file_get_contents($this->log));
        $this->assertFalse($waiting->answered());

        $answer = await($waiting->ask("Alice: \"Claude\", what time is it?\nBob: Yes, please. ⏰", $this->collect(...)));

        $this->assertSame('It is a quarter past four.', $answer);
        $this->assertSame(['It is a quarter', ' past four.'], $this->pieces);
        $this->assertTrue($waiting->answered());

        // The process that was waiting answered, and got the prompt as one message on one line.
        $log = file_get_contents($this->log);
        $this->assertStringContainsString("pid={$pid}\n", $log);
        $this->assertStringEndsWith(
            'stdin={"type":"user","message":{"role":"user","content":"Alice: \"Claude\", what time is it?\nBob: Yes, please. \\u23f0"}}' . "\n",
            $log,
        );

        // Everything else is as for a process started for the prompt.
        $args = $this->arguments($log);
        $this->assertSame(
            ['--print', '--output-format', 'stream-json', '--verbose', '--include-partial-messages', '--model', 'haiku'],
            array_slice($args, 0, 7),
        );
        $this->assertStringContainsString('Discord voice call', $this->option($args, '--system-prompt'));
        $this->assertSame('', $this->option($args, '--tools'));
        $this->assertContains('--strict-mcp-config', $args);
        $this->assertContains('--no-session-persistence', $args);
        $this->assertSame('', $this->option($args, '--setting-sources'));
        $this->assertSame('stream-json', $this->option($args, '--input-format'));
        $this->assertStringContainsString("cwd={$this->workingDirectory}\n", $log);
        $this->assertStringContainsString("api_key=unset\nthinking=unset\nnonessential_traffic=1\nautoupdater=1\n", $log);

        // It answers one prompt, and has ended once it did.
        await($waiting->ended());
        $this->assertFalse(posix_kill($pid, 0));
    }

    public function testAWaitingProcessCanBeToldWhatToDoAndNotToThink(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeResult('They agreed to meet on Friday.'));

        $waiting = $this->waiting($this->claude()->wait('You summarize voice calls.', thinks: false));

        // Without a callback, and an answer that wasn't streamed.
        $this->assertSame('They agreed to meet on Friday.', await($waiting->ask("Alice: Let's meet on Friday.")));

        $log = file_get_contents($this->log);
        $this->assertSame('You summarize voice calls.', $this->option($this->arguments($log), '--system-prompt'));
        $this->assertStringContainsString("thinking=0\n", $log);
    }

    public function testAWaitingProcessMaySaySomethingBeforeItIsAsked(): void
    {
        // Claude Code 2.1.289 prints nothing until it is asked, but its events about the session come first
        // in its output, and another version may send them before the prompt is there.
        putenv('FAKE_CLAUDE_WAITING_GREETS={"type":"system","subtype":"init","session_id":"00000000-0000-4000-8000-000000000000"}');
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeStream('Paris.'));
        putenv("FAKE_CLAUDE_WAITING={$this->log}.waiting");

        $waiting = $this->waiting($this->claude()->wait());
        $pid = $this->waitingPid();
        delay(0.3);

        // It is still waiting, and what it said was no answer.
        $this->assertTrue(posix_kill($pid, 0));
        $this->assertFalse($waiting->answered());

        $this->assertSame('Paris.', await($waiting->ask('Hello', $this->collect(...))));
        $this->assertSame(['Paris.'], $this->pieces);
        $this->assertStringContainsString("pid={$pid}\n", file_get_contents($this->log));
    }

    public function testAWaitingProcessNeverBreaksItsMessageOnWhatWasMisheard(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeResult('Sorry?'));

        $waiting = $this->waiting($this->claude()->wait());

        // Bytes that aren't UTF-8 can't be written as JSON: without a message, Claude Code would wait forever.
        $this->assertSame('Sorry?', await($waiting->ask("Alice: Caf\xE9?")));
        $this->assertStringEndsWith('"content":"Alice: Caf\\ufffd?"}}' . "\n", file_get_contents($this->log));
    }

    public function testAWaitingProcessRejectsLikeAnyOtherWhenClaudeCodeReportsAnError(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeResult('Not logged in · Please run /login', isError: true));
        putenv('FAKE_CLAUDE_EXIT=1');

        $waiting = $this->waiting($this->claude()->wait());

        try {
            await($waiting->ask('Hello', $this->collect(...)));
            $this->fail('The question should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Claude Code: Not logged in · Please run /login', $e->getMessage());
        }

        $this->assertSame([], $this->pieces);
        $this->assertTrue($waiting->answered(), 'Claude said why there is no answer: asking again would not help.');
    }

    public function testAWaitingProcessThatFailsWhileWritingHasAnswered(): void
    {
        putenv('FAKE_CLAUDE_OUTPUT=' . self::claudeText('It is a quarter past four.'));
        putenv('FAKE_CLAUDE_EXIT=3');

        $waiting = $this->waiting($this->claude()->wait());

        try {
            await($waiting->ask('Hello', $this->collect(...)));
            $this->fail('The question should have failed.');
        } catch (CommandFailedException $e) {
            // Like a process started for the prompt, it never quotes what Claude wrote.
            $this->assertStringEndsWith('fake-claude exited with code 3', $e->getMessage());
        }

        $this->assertSame(['It is a quarter past four.'], $this->pieces);
        $this->assertTrue($waiting->answered(), 'Part of the answer was handed over: asking again would repeat it.');
    }

    public function testAWaitingProcessThatEndsWithoutWritingAnythingHasNotAnswered(): void
    {
        putenv('FAKE_CLAUDE_WAITING_FAILS=1');

        $waiting = $this->waiting($this->claude()->wait());

        try {
            await($waiting->ask('Hello', $this->collect(...)));
            $this->fail('The question should have failed.');
        } catch (CommandFailedException $e) {
            $this->assertStringEndsWith('fake-claude exited with code 1', $e->getMessage());
        }

        $this->assertFalse($waiting->answered(), 'The prompt can be given to another process.');
        $this->assertSame([], $this->pieces);
    }

    public function testAWaitingProcessThatEndedByItselfHasNotAnswered(): void
    {
        // Claude Code ends by itself when it is left waiting for some minutes.
        putenv('FAKE_CLAUDE_WAITING_LEAVES=1');

        $waiting = $this->waiting($this->claude()->wait());
        $this->assertNull(await($waiting->ended()), 'Nothing is wrong with that.');

        try {
            await($waiting->ask('Hello', $this->collect(...)));
            $this->fail('The question should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Unexpected output from Claude Code: no result.', $e->getMessage());
        }

        $this->assertFalse($waiting->answered());
        $this->assertSame('', file_get_contents($this->log), 'Nobody got the prompt.');
    }

    public function testAWaitingProcessIsStoppedWhenNothingIsLeftToWaitFor(): void
    {
        putenv("FAKE_CLAUDE_WAITING={$this->log}.waiting");

        $waiting = $this->waiting($this->claude()->wait());
        $pid = $this->waitingPid();
        $this->assertTrue(posix_kill($pid, 0));

        $waiting->stop();

        // Having been stopped is no failure for whoever waits for it to end.
        $this->assertNull(await($waiting->ended()));
        $this->assertFalse(posix_kill($pid, 0));
        $this->assertFalse($waiting->answered());
        $this->assertSame('', file_get_contents($this->log));
    }

    private function claude(): Claude
    {
        return new Claude(__DIR__ . '/../../Fixtures/fake-claude', 'haiku', $this->workingDirectory);
    }

    /**
     * Remembers a waiting process, so that it is stopped when the test is over: left running, it would keep the tests from ending.
     */
    private function waiting(WaitingClaude $waiting): WaitingClaude
    {
        return $this->waiting[] = $waiting;
    }

    /**
     * The process ID of the Claude Code that was started to wait, once it is running.
     */
    private function waitingPid(): int
    {
        for ($i = 0; $i < 100 && ! is_file("{$this->log}.waiting"); $i++) {
            delay(0.05);
        }

        return (int) file_get_contents("{$this->log}.waiting");
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
