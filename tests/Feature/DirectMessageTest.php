<?php

declare(strict_types=1);

namespace Tests\Feature;

use RuntimeException;

final class DirectMessageTest extends VoiceTestCase
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

    public function testAnswersADirectMessageWithTheMemoryAndTheRecentMessages(): void
    {
        $this->memory()->save('555', '- Is building a game called Bananas.');
        $this->dms['555'] = [
            (object) ['content' => 'When should we ship?', 'author' => (object) ['bot' => false]],
            // Something without text, like a picture.
            (object) ['content' => '', 'author' => (object) ['bot' => false]],
            (object) ['content' => 'Which version do you mean?', 'author' => (object) ['bot' => true]],
        ];

        $this->write('The beta, on Friday?');
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        // The bot shows it is typing, reads the DM's last 20 messages, and answers there in text.
        $this->assertSame(['typing 555', 'history 555', 'sent 555'], $this->events);
        $this->assertSame([['limit' => 20]], $this->historyOptions);
        $this->assertSame([self::ANSWER], $this->sent);
        $this->assertSame([], $this->played, 'Nothing is spoken.');

        // Claude got what the bot remembers about Alice and their conversation, oldest message first.
        $this->assertSame(
            "What you remember about Alice:\n\n- Is building a game called Bananas.\n\n"
            . "The recent messages of your chat with Alice:\n\n"
            . "Alice: When should we ship?\nClaude: Which version do you mean?\nAlice: The beta, on Friday?\n\n"
            . "Reply to this message from Alice:\n\nThe beta, on Friday?",
            $this->lastPrompt(),
        );

        // It is asked to chat in text, not to speak in a call.
        $systemPrompt = $this->lastSystemPrompt();
        $this->assertStringStartsWith("You are Claude, this person's personal assistant", $systemPrompt);
        $this->assertStringContainsString("Discord's markdown is allowed", $systemPrompt);
        $this->assertStringContainsString('under 2000 characters, so it fits in one Discord message', $systemPrompt);
        $this->assertStringNotContainsString('read aloud', $systemPrompt);
        $this->assertClaudeWasRestricted();

        $answered = $this->logged('Answered a DM');
        $this->assertCount(1, $answered);
        $this->assertSame(['user', 'ms', 'characters'], array_keys($answered[0]));
        $this->assertSame('555', $answered[0]['user']);
        $this->assertSame(mb_strlen(self::ANSWER), $answered[0]['characters']);
        $this->assertIsInt($answered[0]['ms']);
        $this->assertLogsNeverMention('Friday', 'Bananas', 'Which version');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testOnlyGivesClaudeTheLastTwentyMessages(): void
    {
        $this->dms['555'] = array_map(
            fn (int $number) => (object) ['content' => "Message {$number}.", 'author' => (object) ['bot' => false]],
            range(1, 30),
        );

        $this->write('And this one.');
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        $prompt = $this->lastPrompt();
        $this->assertStringContainsString("your chat with Alice:\n\nAlice: Message 12.\n", $prompt);
        $this->assertStringContainsString("Alice: Message 30.\nAlice: And this one.\n\nReply to this message", $prompt);
        $this->assertSame(20, substr_count($prompt, "\nAlice: "));
    }

    public function testTellsClaudeWhenNothingIsRememberedYet(): void
    {
        $this->write('Hello!');
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        $this->assertStringStartsWith("What you remember about Alice:\n\nNothing yet.\n\nThe recent messages", $this->lastPrompt());
        $this->assertDirectoryDoesNotExist($this->memories, 'Answering a message remembers nothing by itself.');
    }

    public function testIgnoresMessagesFromBotsInServersAndWithoutText(): void
    {
        // Including the bot's own messages, which Discord sends back to it.
        $this->write('I am a bot too.', userId: '999', name: 'Bot', bot: true);
        $this->write('Hello, everyone in this channel!', guildId: self::GUILD_ID);
        $this->write('');
        $this->runFor(0.3);

        $this->assertSame([], $this->events);
        $this->assertSame([], $this->sent);
        $this->assertFileDoesNotExist($this->claudeLog, 'Claude was not asked.');
        $this->assertSame([], $this->timers->pending(), 'No memory will be updated.');
    }

    public function testAnswersAPersonsMessagesOneAtATimeInOrder(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0.4']);

        $this->write('First question.');
        $this->write('Second question.');
        $this->waitUntil(fn () => is_file($this->claudeLog) && $this->lastPrompt() !== '', 'Claude to be asked');

        // Claude is working on the first message: the second one waits for its turn.
        $this->assertStringEndsWith("Reply to this message from Alice:\n\nFirst question.", $this->lastPrompt());
        $this->assertSame(['typing 555', 'history 555'], $this->events);

        $this->waitUntil(fn () => count($this->sent) === 2, 'both answers');

        $this->assertSame(['typing 555', 'history 555', 'sent 555', 'typing 555', 'history 555', 'sent 555'], $this->events);
        // The second message was already there when the first was answered, so Claude is told which one to reply to.
        $this->assertStringEndsWith(
            "Alice: First question.\nAlice: Second question.\nClaude: " . self::ANSWER . "\n\nReply to this message from Alice:\n\nSecond question.",
            $this->lastPrompt(),
        );
        $this->assertCount(2, $this->logged('Answered a DM'));
    }

    public function testDoesNotKeepOnePersonWaitingForAnother(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0.4']);

        $this->write('Hi, it is Alice.');
        $this->write('Hi, it is Bob.', userId: '666', name: 'Bob');

        // Bob's message is being answered while Claude still works on Alice's.
        $this->assertSame(['typing 555', 'history 555', 'typing 666', 'history 666'], $this->events);

        $this->waitUntil(fn () => count($this->sent) === 2, 'both answers');

        $this->assertEqualsCanonicalizing(['555', '666'], array_column($this->logged('Answered a DM'), 'user'));
    }

    public function testKeepsShowingTypingWhileClaudeWorks(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0.4']);

        $this->write('Take your time.');

        // Discord shows a typing indicator for about 10 seconds, so the bot repeats it every 8.
        $this->assertContains(8.0, $this->timers->pending());
        $this->assertSame(1, $this->timers->elapse(8.0));
        $this->assertSame(1, $this->timers->elapse(8.0));
        $this->assertSame(['typing 555', 'history 555', 'typing 555', 'typing 555'], $this->events);

        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        $this->assertNotContains(8.0, $this->timers->pending(), 'It stops once Claude answered.');
    }

    public function testAnswersEvenWhenTypingCannotBeShown(): void
    {
        $this->typingError = new RuntimeException('Discord API unavailable');

        $this->write('Hello!');
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        $this->assertSame([self::ANSWER], $this->sent);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testSplitsAnAnswerThatDoesNotFitInOneMessage(): void
    {
        $lines = array_map(fn (int $point) => sprintf('- Point %02d: %sand that was it.', $point, str_repeat('and so on, ', 8)), range(1, 30));
        $answer = implode("\n", $lines);
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => $this->claudeSays($answer)]);

        $this->write('Tell me everything.');
        $this->waitUntil(fn () => count($this->sent) === 2, 'both parts of the answer');

        // Two messages, in order, each ending with a whole line: together they are the answer.
        $this->assertGreaterThan(2000, mb_strlen($answer));
        $this->assertLessThanOrEqual(2000, max(array_map(mb_strlen(...), $this->sent)));
        $this->assertSame($answer, implode("\n", $this->sent));
        $this->assertSame(mb_strlen($answer), $this->logged('Answered a DM')[0]['characters']);
    }

    public function testKeepsBothHalvesOfASplitCodeBlockAsCode(): void
    {
        $code = implode("\n", array_map(fn (int $line) => sprintf('$line%02d = "%s";', $line, str_repeat('x', 40)), range(1, 60)));
        $answer = "Here it is:\n```php\n{$code}\n```\nThat's all.";
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => $this->claudeSays($answer)]);

        $this->write('Show me the code.');
        $this->waitUntil(fn () => count($this->sent) === 2, 'both parts of the answer');

        // The first message closes the block it cut, and the second opens it again.
        $this->assertStringEndsWith("\n```", $this->sent[0]);
        $this->assertStringStartsWith("```php\n\$line", $this->sent[1]);
        $this->assertStringEndsWith("```\nThat's all.", $this->sent[1]);
        $this->assertLessThanOrEqual(2000, max(array_map(mb_strlen(...), $this->sent)));
        $this->assertSame($answer, preg_replace("/\n```\n```php\n/", "\n", implode("\n", $this->sent)));
    }

    public function testTellsThePersonWhyClaudeCouldNotAnswer(): void
    {
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => json_encode(['type' => 'result', 'is_error' => true, 'result' => 'Usage limit reached']),
            'FAKE_CLAUDE_EXIT' => '1',
        ]);

        $this->write('Are you there?');
        $this->waitUntil(fn () => $this->sent !== [], 'the reply');

        $this->assertSame(["Sorry, I couldn't get an answer from Claude. (Claude Code: Usage limit reached)"], $this->sent);
        $this->assertSame(['Could not answer a DM: Claude Code: Usage limit reached'], $this->loggedProblems());
        $this->assertSame([], $this->logged('Answered a DM'));
        $this->assertNotContains(8.0, $this->timers->pending(), 'The bot stops showing it is typing.');

        // The person's next message is answered like any other.
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => $this->claudeSays(self::ANSWER), 'FAKE_CLAUDE_EXIT' => '0']);
        $this->write('And now?');
        $this->waitUntil(fn () => count($this->sent) === 2, 'the answer');

        $this->assertSame(self::ANSWER, $this->sent[1]);
    }

    public function testTellsThePersonWhenClaudeAnswersNothing(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => $this->claudeSays(" \n")]);

        $this->write('Hello?');
        $this->waitUntil(fn () => $this->sent !== [], 'the reply');

        // Discord refuses empty messages, so an empty answer would otherwise go missing without a word.
        $this->assertSame(["Sorry, I couldn't get an answer from Claude. (Claude gave an empty answer.)"], $this->sent);
        $this->assertSame([], $this->logged('Answered a DM'));
    }

    public function testTellsThePersonWhenTheConversationCannotBeRead(): void
    {
        $this->historyError = new RuntimeException('Discord API unavailable');

        $this->write('Hello?');
        $this->waitUntil(fn () => $this->sent !== [], 'the reply');

        $this->assertSame(["Sorry, I couldn't get an answer from Claude. (Discord API unavailable)"], $this->sent);
        $this->assertFileDoesNotExist($this->claudeLog, 'Claude is not asked without the conversation.');
        $this->assertNotContains(8.0, $this->timers->pending(), 'The bot stops showing it is typing.');
    }

    public function testKeepsAnsweringAfterAnAnswerCouldNotBeSent(): void
    {
        $this->sendError = new RuntimeException('Cannot send messages to this user');

        $this->write('Hello?');
        $this->waitUntil(fn () => $this->loggedProblems() !== [], 'the failure');

        $this->assertSame(['Could not send a DM: Cannot send messages to this user'], $this->loggedProblems());

        $this->sendError = null;
        $this->sent = [];
        $this->write('Hello again?');
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        $this->assertSame([self::ANSWER], $this->sent);
    }
}
