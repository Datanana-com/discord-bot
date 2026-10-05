<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventLoopCheck;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

/**
 * Run by tests/Unit/EventLoopCheckTest.php as its "Live" suite: a timer that is left in the loop but ends on its own,
 * as the Discord client's do in tests/Live, which the check does not look at.
 */
final class ShortTimerTest extends TestCase
{
    public function testLeavesATimerThatEndsOnItsOwn(): void
    {
        Loop::addTimer(0.3, fn () => null);

        $this->assertTrue(true);
    }
}
