<?php

declare(strict_types=1);

namespace Tests\Unit\Logs;

use App\Logs\Failures;
use LogicException;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function React\Promise\reject;
use function React\Promise\set_rejection_handler;

final class FailuresTest extends TestCase
{
    private const string SAID = 'what Alice said in the call';

    private TestHandler $logs;

    private Logger $log;

    /** Where a bot that is run for a test writes its logs. */
    private ?string $directory = null;

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
        // As the bot's log file is written.
        $this->logs->setFormatter(new JsonFormatter());
        $this->log = new Logger('test', [$this->logs]);
    }

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            exec('rm -rf ' . escapeshellarg($this->directory));
        }
    }

    public function testAnExceptionIsLoggedWithWhatCalledWhatAndNeverWithWhatItWasCalledWith(): void
    {
        $e = $this->thrownBy(fn () => $this->hears(self::SAID));

        $this->log->error('Something failed: ' . $e->getMessage(), Failures::context($e));

        ['exception' => $exception, 'trace' => $trace] = $this->logs->getRecords()[0]->context;
        $this->assertSame($e, $exception);
        // The last call first, with where it was made.
        $this->assertMatchesRegularExpression('/FailuresTest\.php:\d+ Tests\\\\Unit\\\\Logs\\\\FailuresTest->hears\(\)$/', $trace[0]);
        $this->assertStringEndsWith('FailuresTest->thrownBy()', $trace[2]);
        $this->assertStringEndsWith('FailuresTest->testAnExceptionIsLoggedWithWhatCalledWhatAndNeverWithWhatItWasCalledWith()', $trace[3]);

        // In the log file: its class, message and file, and nothing of what was said.
        $written = $this->logs->getRecords()[0]->formatted;
        $this->assertSame(RuntimeException::class, json_decode($written, true)['context']['exception']['class']);
        $this->assertSame('Something broke', json_decode($written, true)['context']['exception']['message']);
        $this->assertStringContainsString('FailuresTest.php:', json_decode($written, true)['context']['exception']['file']);
        $this->assertStringNotContainsString('Alice', $written);
    }

    public function testACallPhpMadeItselfHasNoFile(): void
    {
        // array_map() calls the function, from no line of any file.
        $e = $this->thrownBy(fn () => array_map(fn () => throw new RuntimeException('Something broke'), [1]));

        $this->assertStringStartsWith('[internal]:0 ', Failures::trace($e)[0]);
        $this->assertMatchesRegularExpression('/FailuresTest\.php:\d+ array_map\(\)$/', Failures::trace($e)[1]);
    }

    public function testLogsTheExceptionPhpEndsWith(): void
    {
        $e = new RuntimeException('Something broke');

        Failures::uncaught($this->log, $e);

        $this->assertSame(
            [[Level::Critical, 'The bot ends: nothing caught an exception: Something broke', Failures::context($e)]],
            $this->logged(),
        );
    }

    /**
     * @param int $type One of the errors PHP ends with.
     */
    #[DataProvider('fatalErrors')]
    public function testLogsTheFatalErrorPhpEndsWith(int $type): void
    {
        Failures::fatal($this->log, [
            'type' => $type,
            // An exception nothing caught is such an error too, printed with its stack trace.
            'message' => "Allowed memory size of 8 bytes exhausted\nStack trace:\n#0 hears('" . self::SAID . "')",
            'file' => '/bot/app/Voice/VoiceSession.php',
            'line' => 12,
        ]);

        $this->assertSame(
            [[Level::Critical, 'The bot ends: Allowed memory size of 8 bytes exhausted', ['file' => '/bot/app/Voice/VoiceSession.php', 'line' => 12]]],
            $this->logged(),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function fatalErrors(): iterable
    {
        yield 'an error' => [E_ERROR];
        yield 'a file that cannot be parsed' => [E_PARSE];
        yield 'an error while PHP starts' => [E_CORE_ERROR];
        yield 'a file that cannot be compiled' => [E_COMPILE_ERROR];
        yield 'an error the code raised' => [E_USER_ERROR];
        yield 'an error that could have been caught' => [E_RECOVERABLE_ERROR];
    }

    public function testLogsNothingWhenPhpEndsOverNoError(): void
    {
        Failures::fatal($this->log, null);
        // The last thing that went wrong, long before PHP ended, was no reason to.
        Failures::fatal($this->log, ['type' => E_WARNING, 'message' => 'unlink(): No such file or directory', 'file' => '/bot/app/x.php', 'line' => 1]);
        Failures::fatal($this->log, ['type' => E_DEPRECATED, 'message' => 'Deprecated', 'file' => '/bot/app/x.php', 'line' => 1]);

        $this->assertSame([], $this->logged());
    }

    public function testRegistersForWhatNothingCaught(): void
    {
        $rejections = set_rejection_handler(null);

        try {
            $ended = [];
            Failures::register($this->log, function (int $code) use (&$ended) {
                $ended[] = $code;
            });

            // PHP calls this with an exception nothing caught, and then ends: with 0, unless it is told otherwise.
            $handler = set_exception_handler(null);
            restore_exception_handler();
            $handler($e = new RuntimeException('Nothing caught this'));
            $this->assertSame([[Level::Critical, 'The bot ends: nothing caught an exception: Nothing caught this', Failures::context($e)]], $this->logged());
            $this->assertSame([255], $ended);

            // Nothing holds these promises, so nothing will ever handle them. The second is logged like the first:
            // react/promise forgets what it was told to call each time it calls it.
            reject($first = new RuntimeException('Nobody handled this'));
            reject($second = new LogicException('Nor this'));

            $this->assertSame([
                [Level::Error, 'A promise was rejected, and nothing handled that: Nobody handled this', Failures::context($first)],
                [Level::Error, 'A promise was rejected, and nothing handled that: Nor this', Failures::context($second)],
            ], array_slice($this->logged(), 1));
        } finally {
            restore_exception_handler();
            set_rejection_handler($rejections);
        }
    }

    public function testABotThatEndsOverAnExceptionSaysWhyInItsLog(): void
    {
        [$code, $terminal, $log] = $this->crash('exception');

        // Not 0: whatever runs the bot can tell that it didn't just stop.
        $this->assertSame(255, $code);
        $last = end($log);
        $this->assertSame(['CRITICAL', 'The bot ends: nothing caught an exception: Something broke'], [$last['level_name'], $last['message']]);
        $this->assertSame('RuntimeException', $last['context']['exception']['class']);
        $this->assertStringContainsString('crash.php:', $last['context']['exception']['file']);
        $this->assertMatchesRegularExpression('/crash\.php:\d+ hears\(\)$/', $last['context']['trace'][0]);

        // Neither the log nor the terminal has the stack trace PHP would print, with what was said in it.
        $this->assertStringContainsString('The bot ends: nothing caught an exception: Something broke', $terminal);
        $this->assertStringNotContainsString('Alice', $terminal . json_encode($log));
        $this->assertStringNotContainsString('The bot goes on', $terminal);
    }

    public function testABotThatEndsOverAFatalErrorSaysWhyInItsLog(): void
    {
        [$code, , $log] = $this->crash('fatal');

        $this->assertSame(255, $code);
        $last = end($log);
        $this->assertSame('CRITICAL', $last['level_name']);
        $this->assertStringStartsWith('The bot ends: Allowed memory size of 33554432 bytes exhausted', $last['message']);
        $this->assertStringContainsString('crash.php', $last['context']['file']);
    }

    public function testABotGoesOnAfterARejectedPromiseNothingHandledAndLogsIt(): void
    {
        [$code, $terminal, $log] = $this->crash('rejection');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('The bot goes on', $terminal);
        $last = end($log);
        $this->assertSame(['ERROR', 'A promise was rejected, and nothing handled that: Nobody handled this'], [$last['level_name'], $last['message']]);
        $this->assertSame('RuntimeException', $last['context']['exception']['class']);
    }

    /**
     * Runs a bot that fails, as a process of its own.
     *
     * @return array{int, string, list<array<string, mixed>>} Its exit code, what it printed, and its log file.
     */
    private function crash(string $how): array
    {
        $this->directory = sys_get_temp_dir() . '/failures-test-' . uniqid();
        mkdir($this->directory);

        exec(
            sprintf('cd %s && %s %s %s 2>&1', escapeshellarg($this->directory), escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__, 2) . '/Fixtures/crash.php'), $how),
            $printed,
            $code,
        );

        return [
            $code,
            implode("\n", $printed),
            array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file(glob("{$this->directory}/logs/*.log")[0], FILE_IGNORE_NEW_LINES)),
        ];
    }

    private function hears(string $said): never
    {
        throw new RuntimeException('Something broke');
    }

    private function thrownBy(callable $throws): Throwable
    {
        try {
            $throws();
        } catch (Throwable $e) {
            return $e;
        }

        $this->fail('Nothing was thrown.');
    }

    /**
     * @return list<array{Level, string, array<string, mixed>}>
     */
    private function logged(): array
    {
        return array_map(fn ($record) => [$record->level, $record->message, $record->context], $this->logs->getRecords());
    }
}
