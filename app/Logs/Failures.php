<?php

declare(strict_types=1);

namespace App\Logs;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

use function React\Promise\set_rejection_handler;

/**
 * Has what nothing else caught written to the bot's log, and says how an exception is logged.
 *
 * PHP prints an exception nothing caught, a fatal error and a rejected promise nothing handled to the
 * terminal, or to its own error log: the bot's log, which is what is read afterwards, then doesn't say why
 * the bot ended, or that something failed.
 *
 * An exception is logged with its class, its message, the file and line it was thrown at, and what called
 * what to get there. Not as PHP prints a stack trace: that shows the start of every string a function was
 * given, which in a call is what someone said, and what Claude was asked.
 */
final class Failures
{
    /** The errors PHP ends with, which no code can catch. */
    private const int FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    /** Whether PHP is ending over an exception nothing caught. */
    private static bool $uncaught = false;

    /**
     * Has what nothing caught logged, from now on: an exception, which PHP then ends with, a fatal error,
     * and a rejected promise nothing handled, which the bot goes on after.
     *
     * Call it before anything uses the event loop: see {@see ending()}.
     *
     * @param (Closure(int): mixed)|null $end Ends PHP with an exit code: exit(), unless a test replaces it.
     */
    public static function register(LoggerInterface $log, ?Closure $end = null): void
    {
        $end ??= exit(...);
        set_exception_handler(fn (Throwable $e) => self::uncaught($log, $e));
        register_shutdown_function(fn () => self::ending($log, error_get_last(), $end));
        self::rejections($log);
    }

    /**
     * What an exception is logged with, next to its message.
     *
     * @return array{exception: Throwable, trace: list<string>}
     */
    public static function context(Throwable $e): array
    {
        return ['exception' => $e, 'trace' => self::trace($e)];
    }

    /**
     * What called what, the last call first, each with the file and line it was called at. Never with what
     * it was called with.
     *
     * @return list<string>
     */
    public static function trace(Throwable $e): array
    {
        return array_map(
            fn (array $frame) => sprintf(
                '%s:%d %s%s%s()',
                $frame['file'] ?? '[internal]',
                $frame['line'] ?? 0,
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'],
            ),
            $e->getTrace(),
        );
    }

    /**
     * Logs the exception PHP is about to end with.
     */
    public static function uncaught(LoggerInterface $log, Throwable $e): void
    {
        self::$uncaught = true;
        $log->critical('The bot ends: nothing caught an exception: ' . $e->getMessage(), self::context($e));
    }

    /**
     * What happens when PHP ends: the first thing, as it was registered before anything used the event loop.
     *
     * After an exception that was logged here, PHP would end with 0, as if the bot had just stopped, and
     * ReactPHP, which only looks for a fatal error, would first run the event loop the bot never got to run:
     * the bot would connect to Discord half set up, and stay. Ending PHP from here keeps both from happening.
     *
     * @param array{type: int, message: string, file: string, line: int}|null $error What error_get_last() says.
     * @param Closure(int): mixed $end
     */
    public static function ending(LoggerInterface $log, ?array $error, Closure $end): void
    {
        if (self::$uncaught) {
            $end(255);

            return;
        }

        self::fatal($log, $error);
    }

    /**
     * Logs the fatal error PHP is ending with, when that is why it ends.
     *
     * @param array{type: int, message: string, file: string, line: int}|null $error What error_get_last() says.
     */
    public static function fatal(LoggerInterface $log, ?array $error): void
    {
        if ($error === null || ($error['type'] & self::FATAL) === 0) {
            return;
        }

        // Its first line: the ones after it can be a stack trace as PHP prints it.
        $log->critical('The bot ends: ' . strtok($error['message'], "\n"), ['file' => $error['file'], 'line' => $error['line']]);
    }

    private static function rejections(LoggerInterface $log): void
    {
        set_rejection_handler(function (Throwable $e) use ($log) {
            // react/promise takes its handler off before it calls it.
            self::rejections($log);
            $log->error('A promise was rejected, and nothing handled that: ' . $e->getMessage(), self::context($e));
        });
    }
}
