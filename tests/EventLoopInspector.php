<?php

declare(strict_types=1);

namespace Tests;

use Closure;
use LogicException;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\Timer\Timers;
use React\EventLoop\TimerInterface;
use React\Promise\Promise;
use ReflectionFunction;
use ReflectionProperty;
use Throwable;

use function React\Async\await;

/**
 * Looks into ReactPHP's event loop for what is waiting in it: streams and timers.
 * The loop does not list them, so this reads the private properties of {@see StreamSelectLoop}, the one
 * ReactPHP uses when no extension (ev, event, uv) is installed.
 */
final class EventLoopInspector
{
    /**
     * What is waiting in the loop, by a key that is the same for as long as the thing is there.
     *
     * @return array<string, string> Key => what it is, with the addresses of a socket or where a timer's callback is written.
     * @throws LogicException When the loop is not a {@see StreamSelectLoop}, so there is no knowing what is in it.
     */
    public static function waiting(): array
    {
        $loop = self::loop();
        $waiting = [];

        foreach (['read' => ['reading', 'readStreams'], 'write' => ['writing', 'writeStreams']] as $direction => [$doing, $property]) {
            foreach (self::property($loop, $property) as $id => $stream) {
                $waiting["{$direction}:{$id}"] = "{$doing} stream " . self::describeStream($stream);
            }
        }

        foreach (self::timers($loop) as $id => $timer) {
            $kind = $timer->isPeriodic() ? 'every' : 'once after';
            $waiting["timer:{$id}"] = "timer {$kind} {$timer->getInterval()}s, callback " . self::describeCallback($timer->getCallback());
        }

        return $waiting;
    }

    /**
     * Lets the loop finish what await() interrupted, by awaiting one turn of it. react/async runs the loop in a fiber of
     * its own, which await() suspends wherever the promise is settled: in the middle of a timer's callback (delay() does
     * it) or of closing a process's last stream (Shell does). The timer, or the stream, stays in the loop until the next
     * await() resumes the fiber, so a test that ends with one leaves it behind, finished but still registered.
     */
    public static function settle(): void
    {
        await(new Promise(static function (callable $resolve): void {
            Loop::futureTick(fn () => $resolve(null));
        }));
    }

    /**
     * Takes everything out of the loop, so that it has nothing left to wait for when it is run at the end of the program.
     */
    public static function clear(): void
    {
        $loop = self::loop();

        foreach (self::property($loop, 'readStreams') as $stream) {
            $loop->removeReadStream($stream);
        }

        foreach (self::property($loop, 'writeStreams') as $stream) {
            $loop->removeWriteStream($stream);
        }

        foreach (self::timers($loop) as $timer) {
            $loop->cancelTimer($timer);
        }
    }

    private static function loop(): StreamSelectLoop
    {
        $loop = Loop::get();

        return $loop instanceof StreamSelectLoop ? $loop : throw new LogicException('The event loop is a ' . $loop::class . ', and only StreamSelectLoop can be looked into.');
    }

    /**
     * @return array<int|string, TimerInterface>
     */
    private static function timers(LoopInterface $loop): array
    {
        $timers = self::property($loop, 'timers');
        assert($timers instanceof Timers);

        return self::property($timers, 'timers');
    }

    private static function property(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }

    /**
     * A socket by its local and remote address; a stream that was closed without being removed from the loop is said so,
     * because there is nothing to ask of it.
     *
     * @param resource $stream
     */
    private static function describeStream($stream): string
    {
        if (! is_resource($stream)) {
            return 'closed without being removed from the loop';
        }

        $description = (string) (stream_get_meta_data($stream)['stream_type'] ?? get_resource_type($stream));

        foreach (['local' => false, 'remote' => true] as $side => $remote) {
            // Fails for what is not a socket (a pipe), and for a socket that is not connected.
            try {
                $address = stream_socket_get_name($stream, $remote);
            } catch (Throwable) {
                $address = false;
            }

            if ($address !== false && $address !== '') {
                $description .= " {$side} {$address}";
            }
        }

        return $description;
    }

    private static function describeCallback(callable $callback): string
    {
        $function = new ReflectionFunction(Closure::fromCallable($callback));
        $file = $function->getFileName();

        return $file === false ? $function->getName() : basename($file) . ':' . $function->getStartLine();
    }
}
