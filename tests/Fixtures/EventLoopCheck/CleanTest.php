<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventLoopCheck;

use App\Support\Shell;
use PHPUnit\Framework\TestCase;
use React\Socket\SocketServer;

use function React\Async\await;
use function React\Async\delay;

/**
 * Run by tests/Unit/EventLoopCheckTest.php with its own phpunit.xml: tests that close what they opened pass the check,
 * and so do tests that end right after an await(), which suspends the event loop in the middle of what it was doing.
 */
final class CleanTest extends TestCase
{
    public function testClosesWhatItOpened(): void
    {
        $server = new SocketServer('127.0.0.1:0');
        $server->close();

        $this->assertTrue(true);
    }

    public function testEndsAfterAwaitingAProgram(): void
    {
        $this->assertSame("done\n", await(Shell::run(['echo', 'done'])));
    }

    public function testEndsAfterWaiting(): void
    {
        delay(0.05);

        $this->assertTrue(true);
    }
}
