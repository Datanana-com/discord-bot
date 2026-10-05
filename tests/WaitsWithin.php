<?php

declare(strict_types=1);

namespace Tests;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;

use function React\Async\await;
use function React\Promise\race;

/**
 * Waits for what a program that keeps running is expected to do, for so long and no longer.
 *
 * A program that never ends, or never does what it was asked, would keep await() waiting forever: the tests
 * then hang without a word about which one it was, until CI gives up on them.
 */
trait WaitsWithin
{
    /**
     * Waits for a promise like await() does, failing the test when it isn't settled in time.
     */
    protected function within(float $seconds, PromiseInterface $promise, string $what): mixed
    {
        $timeout = new Deferred();
        $timer = Loop::addTimer($seconds, fn () => $timeout->reject(new RuntimeException("Timed out after {$seconds}s waiting for {$what}.")));

        try {
            return await(race([$promise, $timeout->promise()]));
        } finally {
            Loop::cancelTimer($timer);
        }
    }
}
