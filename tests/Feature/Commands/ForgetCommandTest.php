<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Commands\Global\ForgetCommand;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Feature\ChatsInDirectMessages;

final class ForgetCommandTest extends CommandTestCase
{
    use ChatsInDirectMessages;

    private const string MEMORY = '- Is building a game called Bananas.';

    private const array FORGOT = ['content' => 'Done: I forgot what I remembered about you.', 'ephemeral' => true];

    private const array NOTHING_TO_FORGET = ['content' => "I don't remember anything about you, so there is nothing to forget.", 'ephemeral' => true];

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

    #[TestWith([self::GUILD_ID], 'in a server')]
    #[TestWith([null], 'in a direct message')]
    public function testDeletesWhatTheBotRemembersAboutWhoeverAsked(?string $guildId): void
    {
        $this->memory()->save('555', self::MEMORY);
        $this->memory()->save('666', '- Is learning to sail.');

        (new ForgetCommand($this->discord))->handle($this->interaction(null, $guildId, userId: '555'));

        $this->assertSame([self::FORGOT], $this->responses);
        $this->assertFileDoesNotExist("{$this->memories}/555.md");
        $this->assertSame('- Is learning to sail.', $this->memory()->read('666'), 'Nobody else is forgotten.');
    }

    public function testSaysWhenThereIsNothingToForget(): void
    {
        (new ForgetCommand($this->discord))->handle($this->interaction(null));

        $this->assertSame([self::NOTHING_TO_FORGET], $this->responses);
    }

    public function testForgetsWhatWasSaidSinceTheMemoryWasLastUpdated(): void
    {
        $this->memory()->save('555', self::MEMORY);
        $this->chat('My cat is called Whiskers.');

        (new ForgetCommand($this->discord))->handle($this->interaction(null));

        // The conversation would have been remembered once it paused.
        $this->assertSame([self::FORGOT], $this->responses);
        $this->assertSame([], $this->timers->pending());

        // Only what is said from now on is remembered.
        $this->chat('I am learning to sail.');
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => $this->claudeSays('- Is learning to sail.')]);
        $this->assertSame(1, $this->timers->elapse(600.0));
        $this->waitUntil(fn () => $this->logged('Updated memory') !== [], 'the memory');

        $this->assertSame(
            "The current memory:\n\nNothing yet.\n\n"
            . "What was said since it was last updated:\n\nAlice: I am learning to sail.\nClaude: " . self::ANSWER . "\n\n"
            . 'Reply with the new memory.',
            $this->lastPrompt(),
        );
    }

    public function testForgetsAnAnswerThatWasStillBeingWritten(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0.3']);
        $this->write('My cat is called Whiskers.');

        // Claude is still answering what Alice wants forgotten.
        (new ForgetCommand($this->discord))->handle($this->interaction(null));
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        $this->chat('I am learning to sail.');
        $this->assertSame(1, $this->timers->elapse(600.0));
        $this->waitUntil(fn () => $this->logged('Updated memory') !== [], 'the memory');

        $this->assertStringContainsString(
            "What was said since it was last updated:\n\nAlice: I am learning to sail.\nClaude: " . self::ANSWER . "\n\nReply with the new memory.",
            $this->lastPrompt(),
        );
    }

    public function testForgetsMessagesThatWereStillWaitingForTheirAnswer(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0.3', 'FAKE_CLAUDE_OUTPUT' => $this->claudeSays('Noted: you live at 12 Rue X.')]);
        $this->write('My PIN is 1234.');
        // Waits for the first answer, so Claude only starts on it after /forget.
        $this->write('And I live at 12 Rue X.');

        (new ForgetCommand($this->discord))->handle($this->interaction(null));
        $this->waitUntil(fn () => count($this->sent) === 2, 'both answers');

        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0', 'FAKE_CLAUDE_OUTPUT' => $this->claudeSays(self::ANSWER)]);
        $this->chat('I am learning to sail.');
        $this->assertSame(1, $this->timers->elapse(600.0));
        $this->waitUntil(fn () => $this->logged('Updated memory') !== [], 'the memory');

        $this->assertStringContainsString(
            "What was said since it was last updated:\n\nAlice: I am learning to sail.\nClaude: " . self::ANSWER . "\n\nReply with the new memory.",
            $this->lastPrompt(),
        );
    }

    public function testStaysForgottenWhenTheMemoryWasWaitingForAnAnswer(): void
    {
        $this->memory()->save('555', self::MEMORY);
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0.3']);
        $this->write('My cat is called Whiskers.');

        // The conversation paused while Claude was still answering: the update waits for the answer.
        $this->assertSame(1, $this->timers->elapse(600.0));
        (new ForgetCommand($this->discord))->handle($this->interaction(null));
        $this->runFor(0.6);

        $this->assertSame([self::ANSWER], $this->sent);
        $this->assertSame([self::FORGOT], $this->responses);
        $this->assertFileDoesNotExist("{$this->memories}/555.md");
        $this->assertStringStartsNotWith('The current memory', $this->lastPrompt(), 'Claude was not asked for a new memory.');
        $this->assertSame([], $this->logged('Updated memory'));
    }

    public function testStaysForgottenWhenTheMemoryWasBeingUpdated(): void
    {
        $this->memory()->save('555', self::MEMORY);
        $this->chat('My cat is called Whiskers.');

        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0.3', 'FAKE_CLAUDE_OUTPUT' => $this->claudeSays(self::MEMORY . "\n- Has a cat called Whiskers.")]);
        $this->assertSame(1, $this->timers->elapse(600.0));
        $this->waitUntil(fn () => str_starts_with($this->lastPrompt(), 'The current memory'), 'Claude to be asked for the new memory');

        // Claude is still writing the new memory, which must not come back once it is done.
        (new ForgetCommand($this->discord))->handle($this->interaction(null));
        $this->runFor(0.6);

        $this->assertSame([self::FORGOT], $this->responses);
        $this->assertFileDoesNotExist("{$this->memories}/555.md");
        $this->assertSame([], $this->logged('Updated memory'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testDoesNotKeepWhatAFailedUpdateWasGivenOnceForgotten(): void
    {
        $this->chat('My cat is called Whiskers.');

        // The update fails while the person asks to be forgotten.
        $this->setProcessEnv([
            'FAKE_CLAUDE_DELAY' => '0.3',
            'FAKE_CLAUDE_OUTPUT' => json_encode(['type' => 'result', 'is_error' => true, 'result' => 'Usage limit reached']),
            'FAKE_CLAUDE_EXIT' => '1',
        ]);
        $this->assertSame(1, $this->timers->elapse(600.0));
        $this->waitUntil(fn () => str_starts_with($this->lastPrompt(), 'The current memory'), 'Claude to be asked for the new memory');
        (new ForgetCommand($this->discord))->handle($this->interaction(null));
        $this->waitUntil(fn () => $this->loggedProblems() !== [], 'the update to fail');

        // The next update only gets what was said after /forget.
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0', 'FAKE_CLAUDE_OUTPUT' => $this->claudeSays(self::ANSWER), 'FAKE_CLAUDE_EXIT' => '0']);
        $this->chat('I live in Lisbon.');
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => $this->claudeSays('- Lives in Lisbon.')]);
        $this->assertSame(1, $this->timers->elapse(600.0));
        $this->waitUntil(fn () => $this->logged('Updated memory') !== [], 'the next update');

        $this->assertStringNotContainsString('Whiskers', $this->lastPrompt());
        $this->assertStringContainsString('Alice: I live in Lisbon.', $this->lastPrompt());
    }
}
