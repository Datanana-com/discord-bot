<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\GuardedLoop;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
use RuntimeException;
use Throwable;
use TypeError;

/**
 * Every test runs a loop of its own to its end: an exception that left a callback would leave run() too,
 * and fail the test, as it would end the bot.
 */
final class GuardedLoopTest extends TestCase
{
    private GuardedLoop $loop;

    /** The loop it guards. What is added to it directly runs unguarded. */
    private StreamSelectLoop $inner;

    /** @var list<Throwable> What the loop's callbacks threw. */
    private array $caught = [];

    protected function setUp(): void
    {
        $this->inner = new StreamSelectLoop();
        $this->loop = new GuardedLoop($this->inner, function (Throwable $e) {
            $this->caught[] = $e;
        });
    }

    public function testATimerThatThrowsDoesNotEndTheLoop(): void
    {
        $given = null;
        $later = false;
        $timer = $this->loop->addTimer(0.01, function (TimerInterface $timer) use (&$given) {
            $given = $timer;

            throw new RuntimeException('Something broke in a timer');
        });
        $this->loop->addTimer(0.03, function () use (&$later) {
            $later = true;
        });

        $this->loop->run();

        $this->assertSame(['Something broke in a timer'], $this->messages());
        $this->assertSame($timer, $given, 'The callback is given its timer, as by any loop.');
        $this->assertTrue($later, 'What was to run after it still did.');
    }

    public function testAnErrorIsCaughtLikeAnException(): void
    {
        // What DiscordPHP throws on, and what a bug in the bot mostly is.
        $this->loop->addTimer(0.01, fn () => strlen([]));

        $this->loop->run();

        $this->assertCount(1, $this->caught);
        $this->assertInstanceOf(TypeError::class, $this->caught[0]);
    }

    public function testAPeriodicTimerThatThrowsRunsAgainAtItsNextTime(): void
    {
        $runs = 0;
        $timer = $this->loop->addPeriodicTimer(0.01, function () use (&$runs, &$timer) {
            if (++$runs === 3) {
                $this->loop->cancelTimer($timer);
            }

            throw new RuntimeException("Run {$runs} broke");
        });
        $started = microtime(true);

        $this->loop->run();

        // Thrown on, the timer would be due at once for ever: the loop sets a timer's next time after its callback.
        $this->assertSame(['Run 1 broke', 'Run 2 broke', 'Run 3 broke'], $this->messages());
        $this->assertGreaterThanOrEqual(0.03, microtime(true) - $started, 'Each run waited for its time.');
    }

    public function testAFutureTickThatThrowsDoesNotEndTheLoop(): void
    {
        $later = false;
        $this->loop->futureTick(fn () => throw new RuntimeException('Something broke in a tick'));
        $this->loop->futureTick(function () use (&$later) {
            $later = true;
        });

        $this->loop->run();

        $this->assertSame(['Something broke in a tick'], $this->messages());
        $this->assertTrue($later);
    }

    public function testAStreamsListenerThatThrowsIsCalledForWhatArrivesNext(): void
    {
        [$ours, $theirs] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $read = [];
        $this->loop->addReadStream($ours, function ($stream) use (&$read, $theirs) {
            $read[] = fread($stream, 100);

            if (count($read) === 1) {
                fwrite($theirs, 'second');
            } else {
                $this->loop->removeReadStream($stream);
            }

            throw new RuntimeException('Could not handle ' . end($read));
        });
        fwrite($theirs, 'first');

        $this->loop->run();

        $this->assertSame(['first', 'second'], $read);
        $this->assertSame(['Could not handle first', 'Could not handle second'], $this->messages());
    }

    public function testAStreamThatCanBeWrittenToIsGuardedToo(): void
    {
        [$ours] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->loop->addWriteStream($ours, function ($stream) {
            $this->loop->removeWriteStream($stream);

            throw new RuntimeException('Could not write');
        });

        $this->loop->run();

        $this->assertSame(['Could not write'], $this->messages());
    }

    public function testASignalsListenerThatThrowsDoesNotEndTheLoop(): void
    {
        $received = [];
        $listener = function (int $signal) use (&$received, &$listener) {
            $received[] = $signal;
            $this->loop->removeSignal($signal, $listener);

            throw new RuntimeException('Could not stop');
        };
        $this->loop->addSignal(SIGUSR1, $listener);
        // Not from a callback of the guarded loop: ReactPHP has PHP handle a signal the moment it arrives, which
        // would be inside that callback, and its guard. Most signals arrive while the loop waits, inside none.
        $this->inner->futureTick(fn () => posix_kill(getmypid(), SIGUSR1));

        $this->loop->run();

        $this->assertSame([SIGUSR1], $received);
        $this->assertSame(['Could not stop'], $this->messages());
    }

    public function testASignalsListenerIsRemovedByItself(): void
    {
        $called = [];
        $removed = function () use (&$called) {
            $called[] = 'removed';
        };
        $kept = function (int $signal) use (&$called, &$kept) {
            $called[] = 'kept';
            $this->loop->removeSignal($signal, $kept);
        };
        $this->loop->addSignal(SIGUSR1, $removed);
        $this->loop->addSignal(SIGUSR1, $kept);

        $this->loop->removeSignal(SIGUSR1, $removed);
        // One that was never added, and one of a signal nothing listens to.
        $this->loop->removeSignal(SIGUSR1, fn () => null);
        $this->loop->removeSignal(SIGUSR2, $kept);
        $this->inner->futureTick(fn () => posix_kill(getmypid(), SIGUSR1));

        $this->loop->run();

        $this->assertSame(['kept'], $called);
        $this->assertSame([], $this->caught);
    }

    public function testStopsWhenToldTo(): void
    {
        $runs = 0;
        $this->loop->addPeriodicTimer(0.01, function () use (&$runs) {
            if (++$runs === 2) {
                $this->loop->stop();
            }
        });

        $this->loop->run();

        $this->assertSame(2, $runs);
        $this->assertSame([], $this->caught, 'What does not throw is not handed over.');
    }

    /**
     * @return list<string>
     */
    private function messages(): array
    {
        return array_map(fn (Throwable $e) => $e->getMessage(), $this->caught);
    }
}
