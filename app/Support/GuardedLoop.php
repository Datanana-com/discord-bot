<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Throwable;

/**
 * An event loop that catches what its callbacks throw.
 *
 * An exception thrown from a timer or a stream's listener is otherwise caught by nothing: it leaves the
 * loop, and PHP ends, with the bot still in its calls. Running the loop again would not help. A timer that
 * threw is neither removed nor set for its next time, so the loop would call it first, and it would throw again.
 *
 * So each callback is run here, and what it throws is handed over instead. The loop then goes on as after
 * any callback: the timer is set for its next time, or is over.
 */
final class GuardedLoop implements LoopInterface
{
    /** @var array<int, list<array{callable, Closure}>> The listeners of each signal, each with what the loop calls in its place. */
    private array $signals = [];

    /**
     * @param Closure(Throwable): void $caught Called with what a callback threw.
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Closure $caught,
    ) {
    }

    public function addReadStream($stream, $listener)
    {
        $this->loop->addReadStream($stream, $this->guard($listener));
    }

    public function addWriteStream($stream, $listener)
    {
        $this->loop->addWriteStream($stream, $this->guard($listener));
    }

    public function removeReadStream($stream)
    {
        $this->loop->removeReadStream($stream);
    }

    public function removeWriteStream($stream)
    {
        $this->loop->removeWriteStream($stream);
    }

    public function addTimer($interval, $callback)
    {
        return $this->loop->addTimer($interval, $this->guard($callback));
    }

    public function addPeriodicTimer($interval, $callback)
    {
        return $this->loop->addPeriodicTimer($interval, $this->guard($callback));
    }

    public function cancelTimer(TimerInterface $timer)
    {
        $this->loop->cancelTimer($timer);
    }

    public function futureTick($listener)
    {
        $this->loop->futureTick($this->guard($listener));
    }

    public function addSignal($signal, $listener)
    {
        $guarded = $this->guard($listener);
        $this->loop->addSignal($signal, $guarded);
        $this->signals[$signal][] = [$listener, $guarded];
    }

    public function removeSignal($signal, $listener)
    {
        foreach ($this->signals[$signal] ?? [] as $index => [$added, $guarded]) {
            if ($added === $listener) {
                $this->loop->removeSignal($signal, $guarded);
                unset($this->signals[$signal][$index]);

                return;
            }
        }
    }

    public function run()
    {
        $this->loop->run();
    }

    public function stop()
    {
        $this->loop->stop();
    }

    private function guard(callable $callback): Closure
    {
        return function (mixed ...$arguments) use ($callback): void {
            try {
                $callback(...$arguments);
            } catch (Throwable $e) {
                ($this->caught)($e);
            }
        };
    }
}
