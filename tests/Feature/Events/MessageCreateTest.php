<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Events\MessageCreate;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MessageCreateTest extends TestCase
{
    public function testHandlesAMessage(): void
    {
        $logs = new TestHandler();
        $discord = static::getStubBuilder(Discord::class)->disableOriginalConstructor()->onlyMethods(['getLogger'])->getStub();
        $discord->method('getLogger')->willReturn(new Logger('test', [$logs]));
        $message = (new ReflectionClass(Message::class))->newInstanceWithoutConstructor();

        // The methods Application::handleEvent() finds on the class, in order.
        $event = new MessageCreate($message, $discord, ['setUp', 'terminateExample']);

        $this->assertTrue($event->handle());
        $this->assertSame(
            ['Log stuff', 'another example', 'Event "terminateExample" was executed successfully.'],
            array_map(fn ($record) => $record->message, $logs->getRecords()),
        );
    }
}
