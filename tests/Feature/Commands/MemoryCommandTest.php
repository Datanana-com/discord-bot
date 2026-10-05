<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Commands\Global\MemoryCommand;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Feature\ChatsInDirectMessages;

final class MemoryCommandTest extends CommandTestCase
{
    use ChatsInDirectMessages;

    private const string MEMORY = "- Is building a game called Bananas.\n- Ships its beta on Friday.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDirectMessages();
    }

    #[TestWith([self::GUILD_ID], 'in a server')]
    #[TestWith([null], 'in a direct message')]
    public function testShowsWhoeverAskedWhatTheBotRemembersAboutThem(?string $guildId): void
    {
        $this->memory()->save('555', self::MEMORY);
        $this->memory()->save('666', '- Is learning to sail.');

        (new MemoryCommand($this->discord))->handle($this->interaction(null, $guildId, userId: '555'));

        $this->assertSame([['content' => "**What I remember about you**\n" . self::MEMORY, 'ephemeral' => true]], $this->responses);
        $this->assertSame([], $this->followUps);
        $this->assertLogsNeverMention('Bananas', 'Friday');
    }

    #[TestWith([self::GUILD_ID], 'in a server')]
    #[TestWith([null], 'in a direct message')]
    public function testSaysWhenNothingIsRememberedYet(?string $guildId): void
    {
        $this->memory()->save('666', '- Is learning to sail.');

        (new MemoryCommand($this->discord))->handle($this->interaction(null, $guildId, userId: '555'));

        $this->assertSame(
            [['content' => "I don't remember anything about you yet. Send me a direct message to chat with me.", 'ephemeral' => true]],
            $this->responses,
        );
        $this->assertSame([], $this->followUps);
    }

    public function testShowsAMemoryThatDoesNotFitInOneMessage(): void
    {
        $lines = array_map(fn (int $fact) => sprintf('- Fact %02d: %sand that is all.', $fact, str_repeat('and so on, ', 7)), range(1, 37));
        $memory = implode("\n", $lines);
        $this->assertGreaterThan(2000, mb_strlen($memory));
        $this->memory()->save('555', $memory);

        (new MemoryCommand($this->discord))->handle($this->interaction(null));

        // The rest follows the response, in order, and is also only shown to whoever asked.
        $this->assertCount(1, $this->responses);
        $this->assertCount(1, $this->followUps);
        $shown = [...$this->responses, ...$this->followUps];
        $this->assertSame([true, true], array_column($shown, 'ephemeral'));
        $this->assertLessThanOrEqual(2000, max(array_map(mb_strlen(...), array_column($shown, 'content'))));
        $this->assertSame("**What I remember about you**\n{$memory}", implode("\n", array_column($shown, 'content')));
    }

    public function testListsTheGroupsYouHaveAMemoryWith(): void
    {
        $this->memory()->save('555', self::MEMORY);
        $this->memory()->save(['555', '666'], '- Plan a trip.');
        $this->memory()->save(['555', '666', '777'], '- Run a chess club.');
        // Not Alice's: Bob and Carol's, and one whose ID only contains hers.
        $this->memory()->save(['666', '777'], '- Share a flat.');
        $this->memory()->save(['5555', '666'], '- Something else.');

        (new MemoryCommand($this->discord))->handle($this->interaction(null));

        $this->assertSame(
            [['content' => "**What I remember about you**\n" . self::MEMORY . "\n\nYou also have memories with: Bob; Bob and Carol.", 'ephemeral' => true]],
            $this->responses,
        );
        $this->assertLogsNeverMention('Bananas', 'chess', 'flat');
    }

    public function testListsTheGroupsEvenWhenYouHaveNoPersonalMemory(): void
    {
        $this->memory()->save(['555', '666'], '- Plan a trip.');

        (new MemoryCommand($this->discord))->handle($this->interaction(null, null));

        $this->assertSame(
            [['content' => "I don't remember anything about you yet. Send me a direct message to chat with me.\n\nYou also have memories with: Bob.", 'ephemeral' => true]],
            $this->responses,
        );
    }

    public function testNamesPeopleAsTheServerAndDiscordDo(): void
    {
        $this->memory()->save(['555', '666'], '- Plan a trip.');
        $this->memory()->save(['555', '777'], '- Run a club.');
        $this->memory()->save(['555', '888'], '- Share a flat.');

        // The server calls Bob "Bobby"; Carol is only known to Discord; nobody knows 888.
        (new MemoryCommand($this->discord))->handle($this->interaction(null, nicknames: ['666' => 'Bobby']));

        $this->assertSame(
            ["I don't remember anything about you yet. Send me a direct message to chat with me.\n\nYou also have memories with: Bobby; Carol; <@888>."],
            array_column($this->responses, 'content'),
        );
    }

    #[TestWith([self::GUILD_ID], 'in a server')]
    #[TestWith([null], 'in a direct message')]
    public function testShowsTheMemoryYouShareWithThePeopleYouName(?string $guildId): void
    {
        $this->memory()->save('555', self::MEMORY);
        $this->memory()->save(['555', '666'], '- Plan a trip.');
        $this->memory()->save(['555', '666', '777'], '- Run a chess club.');

        (new MemoryCommand($this->discord))->handle($this->interaction(null, $guildId, users: ['with' => '666']));
        (new MemoryCommand($this->discord))->handle($this->interaction(null, $guildId, users: ['with' => '777', 'with2' => '666']));

        $this->assertSame([
            ['content' => "**What I remember about you and Bob**\n- Plan a trip.", 'ephemeral' => true],
            ['content' => "**What I remember about you and Carol and Bob**\n- Run a chess club.", 'ephemeral' => true],
        ], $this->responses);
    }

    public function testNamesEveryoneInAGroupOfFiveYouAreOneOf(): void
    {
        $this->memory()->save(['555', '666', '777', '888', '1000000000000000001'], '- Run a big club.');

        (new MemoryCommand($this->discord))->handle($this->interaction(null, users: ['with' => '666', 'with2' => '777', 'with3' => '888', 'with4' => '1000000000000000001']));

        $this->assertSame(
            [['content' => "**What I remember about you and Bob, Carol, <@888> and <@1000000000000000001>**\n- Run a big club.", 'ephemeral' => true]],
            $this->responses,
        );
    }

    public function testOnlyShowsAGroupsMemoryToThePeopleInIt(): void
    {
        $this->memory()->save(['555', '666'], '- Plan a trip.');

        // Carol names Alice, and so is asking for the memory of the two of them, which doesn't exist.
        (new MemoryCommand($this->discord))->handle($this->interaction(null, userId: '777', users: ['with' => '555']));

        $this->assertSame(
            [['content' => "I don't remember anything about you and Alice together yet. I remember what is said in my calls with a group of people.", 'ephemeral' => true]],
            $this->responses,
        );
    }

    public function testNamingYourselfOrSomeoneTwiceChangesNothing(): void
    {
        $this->memory()->save('555', self::MEMORY);
        $this->memory()->save(['555', '666'], '- Plan a trip.');

        (new MemoryCommand($this->discord))->handle($this->interaction(null, users: ['with' => '666', 'with2' => '555', 'with3' => '666']));
        // Only yourself: it is your own memory.
        (new MemoryCommand($this->discord))->handle($this->interaction(null, users: ['with' => '555']));

        $this->assertSame([
            ['content' => "**What I remember about you and Bob**\n- Plan a trip.", 'ephemeral' => true],
            ['content' => "**What I remember about you**\n" . self::MEMORY . "\n\nYou also have memories with: Bob.", 'ephemeral' => true],
        ], $this->responses);
    }
}
