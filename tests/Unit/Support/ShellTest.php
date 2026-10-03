<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CommandFailedException;
use App\Support\Shell;
use PHPUnit\Framework\TestCase;

use function React\Async\await;

final class ShellTest extends TestCase
{
    public function testResolvesWithStdout(): void
    {
        $this->assertSame("hello world\n", await(Shell::run(['echo', 'hello world'])));
    }

    public function testPassesInputOnStdin(): void
    {
        $this->assertSame('from stdin', await(Shell::run(['cat'], 'from stdin')));
    }

    public function testArgumentsAreNotInterpretedByTheShell(): void
    {
        $this->assertSame("\$HOME; rm -rf /\n", await(Shell::run(['echo', '$HOME; rm -rf /'])));
    }

    public function testRejectsWithStderrWhenTheCommandFails(): void
    {
        try {
            await(Shell::run(['sh', '-c', 'echo partial; echo boom >&2; exit 3']));
            $this->fail('The command should have failed.');
        } catch (CommandFailedException $e) {
            $this->assertSame('sh exited with code 3: boom', $e->getMessage());
            $this->assertSame("partial\n", $e->stdout);
        }
    }

    public function testSaysWhichSignalKilledTheCommand(): void
    {
        try {
            await(Shell::run(['sh', '-c', 'kill -ILL $$']));
            $this->fail('The command should have failed.');
        } catch (CommandFailedException $e) {
            $this->assertSame('sh was killed by signal 4', $e->getMessage());
        }
    }

    public function testKillsTheCommandAfterTheTimeout(): void
    {
        $started = microtime(true);

        try {
            await(Shell::run(['sleep', '10'], timeout: 0.2));
            $this->fail('The command should have timed out.');
        } catch (CommandFailedException $e) {
            $this->assertStringContainsString('sleep timed out after 0.2s', $e->getMessage());
        }

        $this->assertLessThan(5, microtime(true) - $started);
    }
}
