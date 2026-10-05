<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;

/**
 * An event loop whose timers only run out when the test says so, so a test doesn't have to wait
 * ten minutes for a conversation to pause. Everything else goes to the real event loop.
 */
final class ManualTimers implements LoopInterface
{
    /** @var list<TimerInterface> Timers that were started and not cancelled. */
    private array $timers = [];

    public function addTimer($interval, $callback)
    {
        return $this->timers[] = new Timer($interval, $callback);
    }

    public function addPeriodicTimer($interval, $callback)
    {
        return $this->timers[] = new Timer($interval, $callback, true);
    }

    public function cancelTimer(TimerInterface $timer)
    {
        $this->timers = array_values(array_filter($this->timers, fn (TimerInterface $pending) => $pending !== $timer));
    }

    /**
     * @return list<float> The seconds each pending timer was started with.
     */
    public function pending(): array
    {
        return array_map(fn (TimerInterface $timer) => $timer->getInterval(), $this->timers);
    }

    /**
     * Lets the pending timers of that many seconds run out.
     *
     * @return int How many ran out.
     */
    public function elapse(float $seconds): int
    {
        $due = array_filter($this->timers, fn (TimerInterface $timer) => $timer->getInterval() === $seconds);

        foreach ($due as $timer) {
            if (! $timer->isPeriodic()) {
                $this->cancelTimer($timer);
            }

            ($timer->getCallback())($timer);
        }

        return count($due);
    }

    public function addReadStream($stream, $listener)
    {
        Loop::get()->addReadStream($stream, $listener);
    }

    public function addWriteStream($stream, $listener)
    {
        Loop::get()->addWriteStream($stream, $listener);
    }

    public function removeReadStream($stream)
    {
        Loop::get()->removeReadStream($stream);
    }

    public function removeWriteStream($stream)
    {
        Loop::get()->removeWriteStream($stream);
    }

    public function futureTick($listener)
    {
        Loop::get()->futureTick($listener);
    }

    public function addSignal($signal, $listener)
    {
        Loop::get()->addSignal($signal, $listener);
    }

    public function removeSignal($signal, $listener)
    {
        Loop::get()->removeSignal($signal, $listener);
    }

    public function run()
    {
        Loop::get()->run();
    }

    public function stop()
    {
        Loop::get()->stop();
    }
}
