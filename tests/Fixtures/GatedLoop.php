<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;

use function React\Async\await;

/**
 * An event loop that takes nothing until it is opened, and hands everything to another loop from then on.
 *
 * DiscordPHP's client starts to connect to Discord when it is made. Made with this loop, it never does,
 * while what the bot adds to the loop afterwards, like a timer or a signal's listener, really runs.
 *
 * Running it waits until it is stopped, with React\Async\await(), like everything in the tests: running
 * and stopping the real loop as well would end the run await() is in the middle of.
 */
final class GatedLoop implements LoopInterface
{
    public bool $open = false;

    /** Resolved when the loop is stopped, while it runs. */
    private ?Deferred $stopped = null;

    public function __construct(private readonly LoopInterface $loop)
    {
    }

    public function addReadStream($stream, $listener)
    {
        if ($this->open) {
            $this->loop->addReadStream($stream, $listener);
        }
    }

    public function addWriteStream($stream, $listener)
    {
        if ($this->open) {
            $this->loop->addWriteStream($stream, $listener);
        }
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
        return $this->open ? $this->loop->addTimer($interval, $callback) : new Timer($interval, $callback);
    }

    public function addPeriodicTimer($interval, $callback)
    {
        return $this->open ? $this->loop->addPeriodicTimer($interval, $callback) : new Timer($interval, $callback, true);
    }

    public function cancelTimer(TimerInterface $timer)
    {
        $this->loop->cancelTimer($timer);
    }

    public function futureTick($listener)
    {
        if ($this->open) {
            $this->loop->futureTick($listener);
        }
    }

    public function addSignal($signal, $listener)
    {
        $this->loop->addSignal($signal, $listener);
    }

    public function removeSignal($signal, $listener)
    {
        $this->loop->removeSignal($signal, $listener);
    }

    public function run()
    {
        $this->stopped = new Deferred();
        await($this->stopped->promise());
    }

    public function stop()
    {
        $this->stopped?->resolve(null);
    }
}
