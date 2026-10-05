<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventLoopCheck;

use PHPUnit\Framework\TestCase;
use React\Socket\SocketServer;

/**
 * Run by tests/Unit/EventLoopCheckTest.php with its own phpunit.xml, never with the tests of the bot: what happened
 * on Datanana-com/discord-bot#11, a server started in setUp() that nothing closes.
 */
final class LeakyInSetUpTest extends TestCase
{
    protected function setUp(): void
    {
        new SocketServer('127.0.0.1:0');
    }

    public function testNeedsAServer(): void
    {
        $this->assertTrue(true);
    }
}