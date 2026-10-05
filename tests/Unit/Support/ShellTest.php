<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CommandFailedException;
use App\Support\Shell;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function React\Async\await;
use function React\Async\delay;

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

    public function testStreamHandsOverEachLineWhileTheCommandRuns(): void
    {
        $lines = [];
        $running = Shell::stream(['sh', '-c', 'echo first; sleep 0.5; echo second'], function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        delay(0.25);
        $this->assertSame(['first'], $lines, 'The first line arrived before the command printed the second one.');

        $this->assertNull(await($running), 'The lines were all handed over, so there is no output to resolve with.');
        $this->assertSame(['first', 'second'], $lines);
    }

    public function testStreamOnlyHandsOverWholeLines(): void
    {
        $lines = [];
        $running = Shell::stream(['sh', '-c', 'printf "one\ntw"; sleep 0.3; printf "o\n\nthree"'], function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        delay(0.15);
        $this->assertSame(['one'], $lines, 'The second line is not complete yet.');

        // The last line doesn't end with a newline: it is handed over when the command exits.
        await($running);
        $this->assertSame(['one', 'two', '', 'three'], $lines);
    }

    public function testStreamPassesInputOnStdin(): void
    {
        $lines = [];
        await(Shell::stream(['cat'], function (string $line) use (&$lines) {
            $lines[] = $line;
        }, "from\nstdin"));

        $this->assertSame(['from', 'stdin'], $lines);
    }

    public function testStreamRejectsWithoutQuotingStdoutWhenTheCommandFails(): void
    {
        $lines = [];

        try {
            await(Shell::stream(['sh', '-c', 'echo said aloud; printf partial; exit 3'], function (string $line) use (&$lines) {
                $lines[] = $line;
            }));
            $this->fail('The command should have failed.');
        } catch (CommandFailedException $e) {
            // run() quotes stdout when stderr is empty, but what is streamed may be private, like Claude's answers.
            $this->assertSame('sh exited with code 3', $e->getMessage());
            $this->assertSame('', $e->stdout);
        }

        $this->assertSame(['said aloud', 'partial'], $lines, 'What it printed before failing was still handed over.');
    }

    public function testStreamRejectsWithStderrWhenTheCommandFails(): void
    {
        try {
            await(Shell::stream(['sh', '-c', 'echo partial; echo boom >&2; exit 3'], fn () => null));
            $this->fail('The command should have failed.');
        } catch (CommandFailedException $e) {
            $this->assertSame('sh exited with code 3: boom', $e->getMessage());
        }
    }

    public function testStreamKillsTheCommandAfterTheTimeout(): void
    {
        $lines = [];
        $started = microtime(true);

        try {
            await(Shell::stream(['sh', '-c', 'echo first; exec sleep 10'], function (string $line) use (&$lines) {
                $lines[] = $line;
            }, timeout: 0.2));
            $this->fail('The command should have timed out.');
        } catch (CommandFailedException $e) {
            $this->assertSame('sh timed out after 0.2s', $e->getMessage());
        }

        $this->assertSame(['first'], $lines);
        $this->assertLessThan(5, microtime(true) - $started);
    }

    public function testStreamStopsTheCommandWhenALineCannotBeHandled(): void
    {
        $lines = [];
        $started = microtime(true);

        try {
            await(Shell::stream(['sh', '-c', 'echo first; echo second; exec sleep 10'], function (string $line) use (&$lines) {
                $lines[] = $line;

                throw new RuntimeException("Cannot handle {$line}");
            }));
            $this->fail('The command should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Cannot handle first', $e->getMessage());
        }

        // An exception thrown into the event loop would stop the whole bot instead.
        $this->assertSame(['first'], $lines, 'Nothing more is handed over.');
        $this->assertLessThan(5, microtime(true) - $started);
    }

    public function testStreamRejectsWhenTheLastLineCannotBeHandled(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot handle last');

        await(Shell::stream(['printf', 'last'], fn (string $line) => throw new RuntimeException("Cannot handle {$line}")));
    }
}
