<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventLoopCheck;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

/**
 * Run by tests/Unit/EventLoopCheckTest.php with its own phpunit.xml, never with the tests of the bot:
 * two of these tests leave something in the event loop, which has to fail that run.
 */
final class LeakyTest extends TestCase
{
    public function testLeavesNothing(): void
    {
        $server = new SocketServer('127.0.0.1:0');
        $server->close();

        $this->assertTrue(true);
    }

    public function testLeavesAListeningSocket(): void
    {
        new SocketServer('127.0.0.1:0');

        $this->assertTrue(true);
    }

    public function testLeavesATimer(): void
    {
        Loop::addTimer(3600, fn () => null);

        $this->assertTrue(true);
    }
}
