<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ListeningPort;
use App\Support\Shell;
use PHPUnit\Framework\TestCase;
use Throwable;

use function React\Async\await;
use function React\Async\delay;

final class ListeningPortTest extends TestCase
{
    public function testSaysWhichPortAProcessListensOn(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);

        $this->assertSame($port, ListeningPort::of(getmypid()));

        fclose($socket);
        $this->assertNull(ListeningPort::of(getmypid()), 'Not once it has closed it.');
    }

    public function testSaysOfAProcessWhatItListensOnAndNotWhatAnotherOneDoes(): void
    {
        $mine = stream_socket_server('tcp://127.0.0.1:0');
        $myPort = (int) substr((string) strrchr((string) stream_socket_get_name($mine, false), ':'), 1);
        $other = Shell::open([PHP_BINARY, '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1), "\n"; sleep(30);'], function (string $line) use (&$otherPort) {
            $otherPort = (int) $line;
        });

        try {
            $deadline = microtime(true) + 10.0;

            while (! isset($otherPort) && microtime(true) < $deadline) {
                delay(0.05);
            }

            $this->assertTrue(isset($otherPort), 'The other process listens.');
            $this->assertNotSame($myPort, $otherPort);
            $this->assertSame($otherPort, ListeningPort::of($other->pid()));
            $this->assertSame($myPort, ListeningPort::of(getmypid()));
        } finally {
            $other->stop();

            try {
                await($other->done());
            } catch (Throwable) {
            }

            fclose($mine);
        }
    }

    public function testOnlyTakesWhatIsListenedToOnThisMachineForThePort(): void
    {
        // On every address of the machine, which is not where a program that was told 127.0.0.1 listens.
        $everywhere = stream_socket_server('tcp://0.0.0.0:0');

        try {
            $this->assertNull(ListeningPort::of(getmypid()));
        } finally {
            fclose($everywhere);
        }
    }

    public function testSaysNothingOfAProcessThatListensOnNothing(): void
    {
        $quiet = Shell::open(['sleep', '30']);

        try {
            $this->assertNull(ListeningPort::of($quiet->pid()));
        } finally {
            $quiet->stop();

            try {
                await($quiet->done());
            } catch (Throwable) {
            }
        }
    }

    public function testDoesNotTakeASocketTheProcessInheritedForOneOfItsOwn(): void
    {
        // A program the bot starts has what the bot had open, this socket included: it listens on it no more than
        // the bot's whisper server listens on the bot's connection to Discord.
        $mine = stream_socket_server('tcp://127.0.0.1:0');
        $quiet = Shell::open(['sleep', '30']);

        try {
            $this->assertNull(ListeningPort::of($quiet->pid()));
            $this->assertSame((int) substr((string) strrchr((string) stream_socket_get_name($mine, false), ':'), 1), ListeningPort::of(getmypid()), 'It is still the bot\'s own.');
        } finally {
            $quiet->stop();

            try {
                await($quiet->done());
            } catch (Throwable) {
            }

            fclose($mine);
        }
    }

    public function testSaysNothingOfAProcessThatIsNotThere(): void
    {
        $this->assertNull(ListeningPort::of(0));
        $this->assertNull(ListeningPort::of(PHP_INT_MAX));
    }
}
