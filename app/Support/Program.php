<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

/**
 * A program that keeps running, so that it can be given its input later, and more than once:
 * {@see Shell::open()} starts one. Like everything Shell runs, it never blocks the event loop.
 *
 * It ends by itself, when it is told to with end() or stop(), or with the bot: its stdin is closed
 * then, which ends a program that reads until there is nothing more to read.
 */
final class Program
{
    /** How much of what it printed to stderr is kept, to say why it failed. */
    private const int STDERR_BYTES = 2000;

    private readonly Process $process;

    private readonly Deferred $done;

    private bool $running = true;

    /** Why it was stopped, when stop() was given a reason. */
    private ?string $stopped = null;

    /** What a listener threw, which stopped it. */
    private ?Throwable $failure = null;

    /** Runs out when it is still running too long after end(). */
    private ?TimerInterface $deadline = null;

    /** @var array{stdout: string, stderr: string} The start of a line whose end has not arrived yet. */
    private array $unfinished = ['stdout' => '', 'stderr' => ''];

    private string $stderr = '';

    /**
     * @param list<string> $command
     * @param (Closure(string $line): mixed)|null $onLine
     * @param (Closure(string $line): mixed)|null $onErrorLine
     * @param array<string, string>|null $env
     * @param (Closure(string $bytes): mixed)|null $onBytes
     */
    public function __construct(
        private readonly array $command,
        private readonly ?Closure $onLine,
        private readonly ?Closure $onErrorLine,
        ?string $cwd,
        ?array $env,
        private readonly ?Closure $onBytes = null,
    ) {
        $this->done = new Deferred();
        // Nobody may be waiting for a program to end, and it can still fail.
        $this->done->promise()->catch(static fn () => null);

        $this->process = new Process(Shell::command($command), $cwd, $env);

        try {
            $this->process->start();
        } catch (RuntimeException $e) {
            // It can't be started, as when the bot has no file descriptors or processes left: that is why it
            // has ended. Whoever started it for later, like a call does for its next question, goes on without it.
            $this->running = false;
            $this->done->reject($e);

            return;
        }

        Shell::track($this->process);
        $this->process->stdout->on('data', fn (string $chunk) => $this->onBytes === null ? $this->read('stdout', $chunk) : $this->handOver('stdout', $chunk));
        $this->process->stderr->on('data', fn (string $chunk) => $this->read('stderr', $chunk));
        $this->process->on('exit', $this->exited(...));
    }

    /**
     * Writes to its stdin. What is written to a program that has ended goes nowhere.
     */
    public function write(string $input): void
    {
        if ($this->running) {
            $this->process->stdin->write($input);
        }
    }

    /**
     * Gives it its last input and closes its stdin: a program that reads until there is nothing more
     * to read then ends by itself, once it is done with what it was given.
     *
     * @param float $timeout Seconds it has for that, before it is stopped.
     */
    public function end(string $input = '', float $timeout = 120.0): void
    {
        if (! $this->running || $this->deadline !== null) {
            return;
        }

        $this->process->stdin->end($input);
        $this->deadline = Loop::addTimer($timeout, fn () => $this->stop("timed out after {$timeout}s"));
    }

    /**
     * Stops it now, whatever it is doing.
     *
     * @param string|null $reason Why, for {@see done()} to say instead of which signal ended it.
     */
    public function stop(?string $reason = null): void
    {
        if (! $this->running) {
            return;
        }

        $this->stopped ??= $reason;
        $this->process->stdin->close();
        $this->process->terminate();
    }

    /**
     * Whether it has not ended yet, as far as the event loop has noticed.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Its process ID, while it runs.
     */
    public function pid(): ?int
    {
        return $this->running ? $this->process->getPid() : null;
    }

    /**
     * @return PromiseInterface<null> Resolves when it has ended; rejects with a {@see CommandFailedException} when it
     *                                failed, was stopped or timed out, with what a listener threw, or with why it
     *                                could not be started. Like with {@see Shell::stream()}, the exception never
     *                                quotes stdout.
     */
    public function done(): PromiseInterface
    {
        return $this->done->promise();
    }

    /**
     * Hands over the whole lines of what it printed: the start of the next one waits for its end.
     * What a program prints for $onBytes is handed over as it comes instead.
     *
     * @param 'stdout'|'stderr' $stream
     */
    private function read(string $stream, string $chunk): void
    {
        $lines = explode("\n", $this->unfinished[$stream] . $chunk);
        $this->unfinished[$stream] = array_pop($lines);

        foreach ($lines as $line) {
            $this->handOver($stream, $line);
        }
    }

    /**
     * @param 'stdout'|'stderr' $stream
     */
    private function handOver(string $stream, string $line): void
    {
        $listener = $stream === 'stdout' ? $this->onBytes ?? $this->onLine : $this->onErrorLine;
        $expected = false;

        if ($listener !== null && $this->failure === null) {
            // An exception thrown from a stream's listener would end up in the event loop, and stop the bot.
            try {
                $expected = $listener($line) === true;
            } catch (Throwable $e) {
                $this->failure = $e;
                $this->stop();
            }
        }

        // What it says on stderr that nobody expected may be why it fails. A line that was expected says that it
        // didn't fail over what came before: what is kept starts over, so that a failure is only told with what
        // the program said since, and not with what it said about work it finished long ago.
        if ($stream === 'stderr') {
            $this->stderr = $expected ? '' : substr("{$this->stderr}{$line}\n", -self::STDERR_BYTES);
        }
    }

    private function exited(?int $code, ?int $signal): void
    {
        $this->running = false;

        if ($this->deadline !== null) {
            Loop::cancelTimer($this->deadline);
        }

        // A last line that doesn't end with a newline.
        foreach (['stdout', 'stderr'] as $stream) {
            if ($this->unfinished[$stream] !== '') {
                $this->handOver($stream, $this->unfinished[$stream]);
            }
        }

        if ($this->failure !== null) {
            $this->done->reject($this->failure);

            return;
        }

        if ($code === 0) {
            $this->done->resolve(null);

            return;
        }

        $reason = $this->stopped ?? match (true) {
            $signal !== null => "was killed by signal {$signal}",
            default => 'exited with code ' . ($code ?? 'unknown'),
        };
        // Only the end of what it printed is kept, which may start in the middle of a character: mb_substr()
        // turns what is left of that character into question marks.
        $output = trim($this->stderr);
        $this->done->reject(new CommandFailedException("{$this->command[0]} {$reason}" . ($output !== '' ? ': ' . mb_substr($output, -500) : ''), ''));
    }
}
