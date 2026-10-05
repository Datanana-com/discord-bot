<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventLoopCheck;

use PHPUnit\Framework\TestCase;
use React\Socket\SocketServer;

/**
 * Run by tests/Unit/EventLoopCheckTest.php with its own phpunit.xml, never with the tests of the bot: a server started
 * in setUp(), and a test that is skipped before it is prepared, so PHPUnit never says it finished.
 */
final class LeakySkippedTest extends TestCase
{
    protected function setUp(): void
    {
        new SocketServer('127.0.0.1:0');
        $this->markTestSkipped('Not on this machine.');
    }

    public function testSkipsAfterOpeningAServer(): void
    {
        $this->assertTrue(true);
    }
}
