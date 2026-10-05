<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Events\MessageCreate;
use Discord\Parts\Channel\Message;
use ReflectionClass;
use Tests\Feature\ChatsInDirectMessages;
use Tests\Feature\VoiceTestCase;

/**
 * What the bot does with direct messages is in {@see \Tests\Feature\DirectMessageTest}.
 */
final class MessageCreateTest extends VoiceTestCase
{
    use ChatsInDirectMessages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDirectMessages();
    }

    protected function tearDown(): void
    {
        $this->endDirectMessages();
        parent::tearDown();
    }

    public function testHasClaudeAnswerADirectMessage(): void
    {
        $this->write('Hello!');
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        $this->assertSame([self::ANSWER], $this->sent);
    }

    public function testLeavesAMessageWithoutAnAuthorAlone(): void
    {
        // Not something Discord sends, but a message nobody wrote can't be answered.
        $message = (new ReflectionClass(Message::class))->newInstanceWithoutConstructor();

        $this->assertNull((new MessageCreate($message, $this->discord, ['answerDirectMessage']))->handle());

        $this->assertSame([], $this->timers->pending());
        $this->assertSame([], $this->logs->getRecords());
    }
}
