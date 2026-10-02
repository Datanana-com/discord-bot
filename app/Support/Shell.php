<?php

declare(strict_types=1);

namespace App\Support;

use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

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
     *                                  when the program fails or times out.
     */
    public static function run(
        array $command,
        ?string $input = null,
        ?string $cwd = null,
        ?array $env = null,
        float $timeout = 120.0,
    ): PromiseInterface {
        $deferred = new Deferred();
        $stdout = '';
        $stderr = '';
        $timedOut = false;

        // "exec" replaces the wrapping shell, so terminate() reaches the program itself.
        $process = new Process('exec ' . implode(' ', array_map(escapeshellarg(...), $command)), $cwd, $env);
        $process->start();

        $process->stdout->on('data', function (string $chunk) use (&$stdout) {
            $stdout .= $chunk;
        });
        $process->stderr->on('data', function (string $chunk) use (&$stderr) {
            $stderr .= $chunk;
        });

        $timer = Loop::addTimer($timeout, function () use ($process, &$timedOut) {
            $timedOut = true;
            $process->terminate();
        });

        $process->on('exit', function (?int $code) use ($deferred, $command, $timeout, $timer, &$stdout, &$stderr, &$timedOut) {
            Loop::cancelTimer($timer);

            if ($code === 0) {
                $deferred->resolve($stdout);

                return;
            }

            $reason = $timedOut ? "timed out after {$timeout}s" : 'exited with code ' . ($code ?? 'unknown');
            $output = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
            $deferred->reject(new CommandFailedException("{$command[0]} {$reason}: " . mb_substr($output, 0, 500), $stdout));
        });

        $process->stdin->end($input ?? '');

        return $deferred->promise();
    }
}
