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
}
