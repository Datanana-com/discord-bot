<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\EventAbstract;
use App\Exceptions\EventFunctionNotFoundException;
use Discord\Discord;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
        $this->assertSame('Event "fail" failed with the following error: Something broke', $this->logged()[0]);
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
