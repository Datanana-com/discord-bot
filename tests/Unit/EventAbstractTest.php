<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\EventAbstract;
use App\Exceptions\EventFunctionNotFoundException;
use App\Logs\Failures;
use Discord\Discord;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypeError;

final class EventAbstractTest extends TestCase
{
    private Discord $discord;

    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
        $this->discord = static::getStubBuilder(Discord::class)->disableOriginalConstructor()->onlyMethods(['getLogger'])->getStub();
        $this->discord->method('getLogger')->willReturn(new Logger('test', [$this->logs]));
    }

    public function testPassesTheEventAndDiscordToEachMethod(): void
    {
        $data = (object) ['content' => 'hello'];
        $event = $this->event($data, ['first', 'second']);

        $event->handle();

        $this->assertSame([['first', $data, $this->discord], ['second', $data, $this->discord]], $event->calls);
    }

    public function testStopsAtTheFirstMethodThatReturnsTrue(): void
    {
        $event = $this->event((object) [], ['first', 'stop', 'second']);

        $this->assertTrue($event->handle());
        $this->assertSame(['first', 'stop'], array_column($event->calls, 0));
    }

    public function testMethodsMayIgnoreTheArguments(): void
    {
        $event = $this->event((object) [], ['withoutArguments']);

        $event->handle();

        $this->assertSame([['withoutArguments']], $event->calls);
    }

    public function testOtherPropertiesReadTheEventData(): void
    {
        $data = (object) ['content' => 'hello'];
        $event = $this->event($data, ['first']);

        $this->assertSame($data, $event->message);
        $this->assertSame(['first'], $event->getExecutableMethods());
    }

    public function testItsOwnPropertiesCanBeReadToo(): void
    {
        $event = $this->event((object) [], ['first']);

        $this->assertSame($this->discord->getLogger(), $event->log);
    }

    public function testWarnsWhenThereIsNothingToRun(): void
    {
        $this->assertFalse($this->event((object) [], [])->handle());
        $this->assertSame(['No executable methods were found for this event.'], $this->logged());
    }

    public function testLogsAFailingMethodAndStops(): void
    {
        $event = $this->event((object) [], ['fail', 'first']);

        $this->assertFalse($event->handle());
        $this->assertSame([['fail']], $event->calls);
        $this->assertSame(['Event "fail" failed with the following error: Something broke'], $this->logged());

        // With the event's class, and what called what: not PHP's stack trace, which holds what the methods were given.
        $context = $this->logs->getRecords()[0]->context;
        $this->assertSame($event::class, $context['event']);
        $this->assertInstanceOf(RuntimeException::class, $context['exception']);
        $this->assertSame(Failures::trace($context['exception']), $context['trace']);
    }

    public function testLogsAMethodThatFailsWithAnErrorAndStops(): void
    {
        // An \Error is no \Exception: thrown on, DiscordPHP would throw it again, into PHP's own error log.
        $event = $this->event((object) [], ['mistake', 'first']);

        $this->assertFalse($event->handle());
        $this->assertSame([['mistake']], $event->calls);
        $this->assertSame(['Event "mistake" failed with the following error: strlen(): Argument #1 ($string) must be of type string, array given'], $this->logged());
        $this->assertInstanceOf(TypeError::class, $this->logs->getRecords()[0]->context['exception']);
    }

    public function testFailsForMethodsThatDoNotExist(): void
    {
        $this->expectException(EventFunctionNotFoundException::class);

        $this->event((object) [], ['missing'])->handle();
    }

    /**
     * @param list<string> $methods
     */
    private function event(object $data, array $methods): EventAbstract
    {
        return new class ($data, $this->discord, $methods) extends EventAbstract {
            /** @var list<array<mixed>> */
            public array $calls = [];

            public function first(object $data, Discord $discord): void
            {
                $this->calls[] = ['first', $data, $discord];
            }

            public function second(object $data, Discord $discord): void
            {
                $this->calls[] = ['second', $data, $discord];
            }

            public function stop(): bool
            {
                $this->calls[] = ['stop'];

                return true;
            }

            public function withoutArguments(): void
            {
                $this->calls[] = ['withoutArguments'];
            }

            public function fail(): void
            {
                $this->calls[] = ['fail'];

                throw new RuntimeException('Something broke');
            }

            public function mistake(): int
            {
                $this->calls[] = ['mistake'];

                return strlen([]);
            }
        };
    }

    /**
     * @return list<string>
     */
    private function logged(): array
    {
        return array_map(fn ($record) => $record->message, $this->logs->getRecords());
    }
}
