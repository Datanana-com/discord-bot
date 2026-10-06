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

    /**
     * Has what nothing caught logged, from now on: an exception, which PHP then ends with, a fatal error,
     * and a rejected promise nothing handled, which the bot goes on after.
     *
     * @param (Closure(int): mixed)|null $end Ends PHP with an exit code: exit(), unless a test replaces it.
     */
    public static function register(LoggerInterface $log, ?Closure $end = null): void
    {
        $end ??= exit(...);
        set_exception_handler(function (Throwable $e) use ($log, $end) {
            self::uncaught($log, $e);
            // PHP ends with 0 after an exception that was handled here, as if the bot had just stopped.
            $end(255);
        });
        register_shutdown_function(fn () => self::fatal($log, error_get_last()));
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
        $log->critical('The bot ends: nothing caught an exception: ' . $e->getMessage(), self::context($e));
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
