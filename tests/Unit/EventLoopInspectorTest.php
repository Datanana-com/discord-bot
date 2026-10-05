<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Shell;
use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\SocketServer;
use Tests\EventLoopInspector;

use function React\Async\await;
use function React\Async\delay;

final class EventLoopInspectorTest extends TestCase
{
    /** @var array<string, string> What waited in the loop before the test, which it is not about. */
    private array $before = [];

    protected function setUp(): void
    {
        // What an earlier test that ended with an await() left behind is not what these tests are about.
        EventLoopInspector::settle();
        $this->before = EventLoopInspector::waiting();
    }

    public function testSaysWhichSocketIsListeningWithItsAddress(): void
    {
        $server = new SocketServer('127.0.0.1:0');
        $address = parse_url($server->getAddress(), PHP_URL_HOST) . ':' . parse_url($server->getAddress(), PHP_URL_PORT);

        try {
            $this->assertCount(1, $found = $this->waiting());
            $this->assertStringContainsString('reading stream tcp_socket', current($found));
            $this->assertStringContainsString("local {$address}", current($found));
        } finally {
            $server->close();
        }

        $this->assertSame([], $this->waiting(), 'Closing the server took it out of the loop.');
    }

    public function testSaysWhoIsOnTheOtherEndOfAConnection(): void
    {
        $server = new SocketServer('127.0.0.1:0');
        $client = stream_socket_client($server->getAddress());
        Loop::addWriteStream($client, fn () => null);

        try {
            $writing = array_filter($this->waiting(), fn (string $what) => str_starts_with($what, 'writing'));

            $this->assertCount(1, $writing);
            $this->assertMatchesRegularExpression('/^writing stream tcp_socket\S* local 127\.0\.0\.1:\d+ remote 127\.0\.0\.1:' . parse_url($server->getAddress(), PHP_URL_PORT) . '$/', current($writing));
        } finally {
            Loop::removeWriteStream($client);
            fclose($client);
            $server->close();
        }
    }

    public function testSaysWhatAStreamThatIsNotASocketIs(): void
    {
        $pipe = fopen('php://memory', 'r+');
        Loop::addReadStream($pipe, fn () => null);

        try {
            $this->assertSame(['reading stream MEMORY'], array_values($this->waiting()));
        } finally {
            Loop::removeReadStream($pipe);
        }
    }

    public function testSaysWhenAStreamWasClosedWithoutBeingTakenOutOfTheLoop(): void
    {
        $pipe = fopen('php://memory', 'r+');
        Loop::addReadStream($pipe, fn () => null);
        fclose($pipe);

        try {
            $this->assertSame(['reading stream closed without being removed from the loop'], array_values($this->waiting()));
        } finally {
            Loop::removeReadStream($pipe);
        }
    }

    public function testSaysWhereATimersCallbackIs(): void
    {
        $once = Loop::addTimer(3600, fn () => null);
        $line = __LINE__ - 1;
        $every = Loop::addPeriodicTimer(0.5, [$this, 'testSaysWhereATimersCallbackIs']);

        try {
            $this->assertEqualsCanonicalizing([
                "timer once after 3600s, callback EventLoopInspectorTest.php:{$line}",
                'timer every 0.5s, callback EventLoopInspectorTest.php:' . (new \ReflectionMethod($this, 'testSaysWhereATimersCallbackIs'))->getStartLine(),
            ], array_values($this->waiting()));
        } finally {
            Loop::cancelTimer($once);
            Loop::cancelTimer($every);
        }

        $this->assertSame([], $this->waiting());
    }

    public function testKeepsTheSameKeyForAsLongAsTheThingIsInTheLoop(): void
    {
        $timer = Loop::addTimer(3600, fn () => null);

        try {
            $first = $this->waiting();
            $second = $this->waiting();

            $this->assertCount(1, $first);
            $this->assertSame($first, $second);
        } finally {
            Loop::cancelTimer($timer);
        }
    }

    /**
     * @return iterable<string, array{Closure(): mixed}>
     */
    public static function endsOfATest(): iterable
    {
        yield 'waiting for a timer' => [fn () => delay(0.01)];
        yield 'waiting for a program' => [fn () => await(Shell::run(['true']))];
    }

    /**
     * @param Closure(): mixed $await
     */
    #[DataProvider('endsOfATest')]
    public function testSettleFinishesWhatTheEndOfATestLeftBehind(Closure $await): void
    {
        $await();

        $this->assertNotSame([], $this->waiting(), 'await() leaves a timer or a stream registered, to be finished by the next one.');

        EventLoopInspector::settle();

        $this->assertSame([], $this->waiting());
    }

    public function testSettleDoesNotWaitForWhatIsInTheLoop(): void
    {
        $server = new SocketServer('127.0.0.1:0');
        $started = microtime(true);

        try {
            EventLoopInspector::settle();

            $this->assertLessThan(1, microtime(true) - $started, 'A listening socket would keep the loop running for ever.');
            $this->assertCount(1, $this->waiting());
        } finally {
            $server->close();
        }
    }

    public function testClearTakesEverythingOutOfTheLoop(): void
    {
        $server = new SocketServer('127.0.0.1:0');
        $client = stream_socket_client($server->getAddress());
        Loop::addWriteStream($client, fn () => null);
        Loop::addTimer(3600, fn () => null);
        Loop::addPeriodicTimer(3600, fn () => null);
        $this->assertCount(4, $this->waiting());

        EventLoopInspector::clear();

        $this->assertSame([], $this->waiting());

        fclose($client);
        $server->close();
    }

    public function testCannotLookIntoALoopThatIsNotStreamSelect(): void
    {
        $loop = Loop::get();
        Loop::set($this->createStub(LoopInterface::class));

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessageMatches('/only StreamSelectLoop can be looked into/');

            EventLoopInspector::waiting();
        } finally {
            Loop::set($loop);
        }
    }

    /**
     * What the test put in the loop.
     *
     * @return array<string, string>
     */
    private function waiting(): array
    {
        return array_diff_key(EventLoopInspector::waiting(), $this->before);
    }
}
