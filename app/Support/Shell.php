<?php

declare(strict_types=1);

namespace App\Support;

use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * Runs external programs without blocking the event loop.
 *
 * Blocking calls (exec, shell_exec, ...) would freeze the bot while a program runs,
 * which drops the voice connection's heartbeats, so everything goes through here.
 */
final class Shell
{
    /**
     * Runs a command and collects its output.
     *
     * @param list<string> $command Program followed by its arguments; each one is shell-escaped.
     * @param string|null $input Text written to the program's stdin.
     * @param string|null $cwd Working directory, or null for the bot's own.
     * @param array<string, string>|null $env Environment variables, or null to inherit the bot's.
     * @param float $timeout Seconds before the program is killed.
     * @return PromiseInterface<string> Resolves with stdout; rejects with a {@see CommandFailedException}
     *                                  when the program fails or times out. Its message quotes stderr, never
     *                                  stdout, which can hold something that must not be logged.
     */
    public static function run(
        array $command,
        ?string $input = null,
        ?string $cwd = null,
        ?array $env = null,
        float $timeout = 120.0,
    ): PromiseInterface {
        return self::start($command, null, $input, $cwd, $env, $timeout);
    }

    /**
     * Runs a command and hands over its output line by line, while it is running.
     *
     * @param list<string> $command Program followed by its arguments; each one is shell-escaped.
     * @param callable(string $line): void $onLine Called with each line of stdout, without its line ending.
     *                                             When it throws, the program is stopped.
     * @param string|null $input Text written to the program's stdin.
     * @param string|null $cwd Working directory, or null for the bot's own.
     * @param array<string, string>|null $env Environment variables, or null to inherit the bot's.
     * @param float $timeout Seconds before the program is killed.
     * @return PromiseInterface<null> Resolves when the program is done; rejects with a {@see CommandFailedException}
     *                                when it fails or times out, or with what $onLine threw.
     */
    public static function stream(
        array $command,
        callable $onLine,
        ?string $input = null,
        ?string $cwd = null,
        ?array $env = null,
        float $timeout = 120.0,
    ): PromiseInterface {
        return self::start($command, $onLine, $input, $cwd, $env, $timeout);
    }

    /**
     * @param list<string> $command
     * @param (callable(string): void)|null $onLine Gets stdout line by line; without it, stdout is collected.
     * @param array<string, string>|null $env
     */
    private static function start(array $command, ?callable $onLine, ?string $input, ?string $cwd, ?array $env, float $timeout): PromiseInterface
    {
        $deferred = new Deferred();
        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $failure = null;

        // "exec" replaces the wrapping shell, so terminate() reaches the program itself.
        $process = new Process('exec ' . implode(' ', array_map(escapeshellarg(...), $command)), $cwd, $env);
        $process->start();

        // An exception thrown from a stream's listener would end up in the event loop, and stop the bot.
        $handOver = function (string $line) use ($onLine, $process, &$failure) {
            if ($failure !== null) {
                return;
            }

            try {
                $onLine($line);
            } catch (Throwable $e) {
                $failure = $e;
                $process->terminate();
            }
        };

        $process->stdout->on('data', function (string $chunk) use (&$stdout, $onLine, $handOver) {
            $stdout .= $chunk;

            if ($onLine === null) {
                return;
            }

            // Only whole lines are handed over: the start of the next one waits for its end.
            $lines = explode("\n", $stdout);
            $stdout = array_pop($lines);

            foreach ($lines as $line) {
                $handOver($line);
            }
        });
        $process->stderr->on('data', function (string $chunk) use (&$stderr) {
            $stderr .= $chunk;
        });

        $timer = Loop::addTimer($timeout, function () use ($process, &$timedOut) {
            $timedOut = true;
            $process->terminate();
        });

        $process->on('exit', function (?int $code, ?int $signal) use ($deferred, $command, $timeout, $timer, $onLine, $handOver, &$stdout, &$stderr, &$timedOut, &$failure) {
            Loop::cancelTimer($timer);

            // A last line that doesn't end with a newline.
            if ($onLine !== null && $stdout !== '') {
                $handOver($stdout);
                $stdout = '';
            }

            if ($failure !== null) {
                $deferred->reject($failure);

                return;
            }

            if ($code === 0) {
                $deferred->resolve($onLine === null ? $stdout : null);

                return;
            }

            $reason = match (true) {
                $timedOut => "timed out after {$timeout}s",
                // E.g. a crash, or SIGILL for a program built for a newer CPU.
                $signal !== null => "was killed by signal {$signal}",
                default => 'exited with code ' . ($code ?? 'unknown'),
            };
            // Never stdout: it can hold what someone said, like whisper's transcript, and the message is logged.
            $output = trim($stderr);
            $message = "{$command[0]} {$reason}" . ($output !== '' ? ': ' . mb_substr($output, 0, 500) : '');
            $deferred->reject(new CommandFailedException($message, $stdout));
        });

        $process->stdin->end($input ?? '');

        return $deferred->promise();
    }
}
