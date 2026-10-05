<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CommandFailedException;
use App\Support\Program;
use App\Support\Shell;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function React\Async\await;
use function React\Async\delay;
use function React\Promise\set_rejection_handler;

/**
 * A program that keeps running: {@see Shell::open()}.
 */
final class ProgramTest extends TestCase
{
    /** @var list<Program> Every program a test started: one left running would keep the tests from ending. */
    private array $programs = [];

    /** @var list<string> */
    private array $lines = [];

    /** @var list<string> */
    private array $errorLines = [];

    protected function tearDown(): void
    {
        foreach ($this->programs as $program) {
            $program->stop();
            $this->ended($program);
        }
    }

    public function testAnswersWhatItIsGivenWhileItKeepsRunning(): void
    {
        $program = $this->open(['cat']);

        delay(0.2);
        $this->assertSame([], $this->lines, 'It waits for its input.');
        $this->assertTrue($program->isRunning());

        $program->write("first\n");
        $this->waitFor(1);
        $this->assertSame(['first'], $this->lines);

        // Later, and more than once.
        delay(0.2);
        $program->write("second\nthird\n");
        $this->waitFor(3);
        $this->assertSame(['first', 'second', 'third'], $this->lines);
        $this->assertTrue($program->isRunning(), 'It is the same program that answered each time.');
    }

    public function testEndsOnceItsStdinIsClosed(): void
    {
        $program = $this->open(['cat']);

        $program->end("last\n");

        // Like a program that Shell::stream() ran to its end.
        $this->assertNull(await($program->done()));
        $this->assertSame(['last'], $this->lines, 'It was given its last input first.');
        $this->assertFalse($program->isRunning());
    }

    public function testOnlyHandsOverWholeLines(): void
    {
        $program = $this->open(['sh', '-c', 'printf "one\ntw"; sleep 0.3; printf "o\n\nthree"']);

        delay(0.15);
        $this->assertSame(['one'], $this->lines, 'The second line is not complete yet.');

        // The last line doesn't end with a newline: it is handed over when the program ends.
        await($program->done());
        $this->assertSame(['one', 'two', '', 'three'], $this->lines);
    }

    public function testHandsOverStderrLineByLineToo(): void
    {
        $program = $this->open(['sh', '-c', 'echo out; echo "warning: one" >&2; printf "warning: two" >&2'], errors: true);

        await($program->done());

        $this->assertSame(['out'], $this->lines);
        $this->assertSame(['warning: one', 'warning: two'], $this->errorLines);
    }

    public function testRejectsWithTheEndOfStderrWhenItFails(): void
    {
        $program = $this->open(['sh', '-c', 'echo said aloud; echo boom >&2; exit 3']);

        try {
            await($program->done());
            $this->fail('The program should have failed.');
        } catch (CommandFailedException $e) {
            // What it printed may be private, like Claude's answers: only stderr says why it failed.
            $this->assertSame('sh exited with code 3: boom', $e->getMessage());
            $this->assertSame('', $e->stdout);
        }

        $this->assertSame(['said aloud'], $this->lines, 'What it printed before failing was still handed over.');
        $this->assertFalse($program->isRunning());
    }

    public function testKeepsOnlyTheEndOfALongStderr(): void
    {
        // A program that keeps running can print a lot before it fails. 2400 bytes of it are 600 characters here,
        // so the 2000 bytes that are kept start in the middle of one.
        $program = $this->open([PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("😀", 600) . " The voice could not be loaded.\n"); exit(1);']);

        try {
            await($program->done());
            $this->fail('The program should have failed.');
        } catch (CommandFailedException $e) {
            $start = PHP_BINARY . ' exited with code 1: ';
            $this->assertStringStartsWith($start, $e->getMessage());
            $this->assertStringEndsWith('😀😀 The voice could not be loaded.', $e->getMessage());
            $this->assertSame(mb_strlen($start) + 500, mb_strlen($e->getMessage()), 'The error says at most 500 characters of it.');
            $this->assertTrue(mb_check_encoding($e->getMessage(), 'UTF-8'), 'Half a character would not be logged.');
        }
    }

    public function testNeverSaysHalfACharacterOfStderr(): void
    {
        // 2401 bytes: the 2000 that are kept start with the last three bytes of a character.
        $program = $this->open([PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("😀", 600) . "\n"); exit(1);']);

        try {
            await($program->done());
            $this->fail('The program should have failed.');
        } catch (CommandFailedException $e) {
            $this->assertTrue(mb_check_encoding($e->getMessage(), 'UTF-8'));
            $this->assertStringEndsWith(str_repeat('😀', 499), $e->getMessage());
            $this->assertStringNotContainsString(str_repeat('😀', 500), $e->getMessage());
        }
    }

    public function testOnlyQuotesTheStderrItsListenerDidNotExpect(): void
    {
        // Like Piper, which says on stderr what it did, and why it fails.
        $program = $this->open(['sh', '-c', 'echo "INFO: wrote a file" >&2; echo boom >&2; echo "INFO: wrote another" >&2; printf "bang" >&2; exit 3'], errors: true);

        try {
            await($program->done());
            $this->fail('The program should have failed.');
        } catch (CommandFailedException $e) {
            $this->assertSame("sh exited with code 3: boom\nbang", $e->getMessage());
        }

        $this->assertSame(['INFO: wrote a file', 'boom', 'INFO: wrote another', 'bang'], $this->errorLines, 'It was handed all of it.');
    }

    public function testStopsAProgramThatIsWaiting(): void
    {
        $program = $this->open(['cat']);
        $started = microtime(true);

        $program->stop();

        try {
            await($program->done());
            $this->fail('The program was stopped.');
        } catch (CommandFailedException $e) {
            $this->assertSame('cat was killed by signal 15', $e->getMessage());
        }

        $this->assertFalse($program->isRunning());
        $this->assertLessThan(5, microtime(true) - $started);

        // Stopping or ending it again, and writing to it, do nothing.
        $program->stop();
        $program->end('more');
        $program->write('more');
        delay(0.1);
        $this->assertSame([], $this->lines);
    }

    public function testSaysWhyItWasStopped(): void
    {
        $program = $this->open(['sh', '-c', 'exec sleep 10']);

        $program->stop('was no longer needed');
        // Stopping it again doesn't take the reason back.
        $program->stop();

        $this->expectException(CommandFailedException::class);
        $this->expectExceptionMessage('sh was no longer needed');

        await($program->done());
    }

    public function testStopsAProgramThatDoesNotEndInTimeAfterItsLastInput(): void
    {
        // It ignores that its stdin is closed.
        $program = $this->open(['sh', '-c', 'cat; exec sleep 10']);
        $started = microtime(true);

        $program->end("last\n", timeout: 0.3);

        try {
            await($program->done());
            $this->fail('The program should have timed out.');
        } catch (CommandFailedException $e) {
            $this->assertSame('sh timed out after 0.3s', $e->getMessage());
        }

        $this->assertSame(['last'], $this->lines);
        $this->assertLessThan(5, microtime(true) - $started);
    }

    public function testATimeoutThatIsNotNeededDoesNotKeepTheBotFromEnding(): void
    {
        // PHP only exits once nothing is left on the event loop: a timer of 30 seconds would be. Not the one of a
        // program that ended in time, nor one for a program that was already told to end, or has ended.
        $script = 'require "vendor/autoload.php"; $program = App\Support\Shell::open(["cat"]); $program->end("", 30.0); $program->end("", 30.0);'
            . ' React\Async\await($program->done()); $program->end("", 30.0);';
        $started = microtime(true);

        await(Shell::run([PHP_BINARY, '-r', $script], cwd: dirname(__DIR__, 3)));

        $this->assertLessThan(10, microtime(true) - $started);
    }

    public function testStopsTheProgramWhenALineCannotBeHandled(): void
    {
        $started = microtime(true);
        $program = Shell::open(['sh', '-c', 'echo first; echo second; exec sleep 10'], function (string $line) {
            $this->lines[] = $line;

            throw new RuntimeException("Cannot handle {$line}");
        });
        $this->programs[] = $program;

        try {
            await($program->done());
            $this->fail('The program should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Cannot handle first', $e->getMessage());
        }

        // An exception thrown into the event loop would stop the whole bot instead.
        $this->assertSame(['first'], $this->lines, 'Nothing more is handed over.');
        $this->assertLessThan(5, microtime(true) - $started);
    }

    public function testRejectsWhenTheLastLineCannotBeHandled(): void
    {
        $program = Shell::open(['printf', 'last'], onErrorLine: fn () => null, onLine: fn (string $line) => throw new RuntimeException("Cannot handle {$line}"));
        $this->programs[] = $program;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot handle last');

        await($program->done());
    }

    public function testRunsInTheGivenDirectoryAndEnvironment(): void
    {
        $program = Shell::open(['sh', '-c', 'pwd; echo "$GREETING"'], function (string $line) {
            $this->lines[] = $line;
        }, cwd: '/tmp', env: ['GREETING' => 'hello']);
        $this->programs[] = $program;

        await($program->done());

        $this->assertSame(['/tmp', 'hello'], $this->lines);
    }

    public function testAFailureNobodyWaitsForIsNotReported(): void
    {
        $unhandled = [];
        $previous = set_rejection_handler(function (Throwable $e) use (&$unhandled) {
            $unhandled[] = $e->getMessage();
        });

        try {
            $program = $this->open(['sh', '-c', 'exit 3']);

            for ($i = 0; $i < 100 && $program->isRunning(); $i++) {
                delay(0.05);
            }

            // A rejection nobody handled is reported when its promise is destroyed.
            unset($program);
            $this->programs = [];
            gc_collect_cycles();
        } finally {
            set_rejection_handler($previous);
        }

        $this->assertSame([], $unhandled);
    }

    /**
     * @param list<string> $command
     * @param bool $errors Whether its stderr is handed over too.
     */
    private function open(array $command, bool $errors = false): Program
    {
        return $this->programs[] = Shell::open(
            $command,
            function (string $line) {
                $this->lines[] = $line;
            },
            $errors ? function (string $line): bool {
                $this->errorLines[] = $line;

                return str_starts_with($line, 'INFO');
            } : null,
        );
    }

    /**
     * Runs the event loop until the program has printed that many lines.
     */
    private function waitFor(int $lines): void
    {
        for ($i = 0; $i < 100 && count($this->lines) < $lines; $i++) {
            delay(0.05);
        }
    }

    /**
     * Runs the event loop until the program has ended, however it does.
     */
    private function ended(Program $program): void
    {
        try {
            await($program->done());
        } catch (Throwable) {
        }
    }
}
