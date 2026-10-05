<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\Memory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class MemoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        // Two folders deep, as MEMORY_PATH can be.
        $this->directory = sys_get_temp_dir() . '/memory-test-' . uniqid() . '/memories';
    }

    protected function tearDown(): void
    {
        unset($_ENV['MEMORY_PATH']);
        exec('rm -rf ' . escapeshellarg(dirname($this->directory)));
    }

    public function testRemembersEachPersonInTheirOwnFile(): void
    {
        $memory = new Memory($this->directory);

        $this->assertSame('- Likes tea.', $memory->save('555', "\n- Likes tea.\n\n"));
        $memory->save('666', '- Likes coffee.');

        $this->assertSame('- Likes tea.', $memory->read('555'));
        $this->assertSame('- Likes coffee.', $memory->read('666'));
        $this->assertSame("- Likes tea.\n", file_get_contents("{$this->directory}/555.md"));
    }

    public function testRemembersNothingAboutSomeoneNew(): void
    {
        $this->assertSame('', (new Memory($this->directory))->read('555'));
        $this->assertDirectoryDoesNotExist($this->directory, 'Reading creates nothing.');
    }

    public function testReplacesWhatWasRemembered(): void
    {
        $memory = new Memory($this->directory);
        $memory->save('555', '- Likes tea.');

        $memory->save('555', '- Likes coffee now.');

        $this->assertSame('- Likes coffee now.', $memory->read('555'));
        $this->assertSame(['555.md'], array_values(array_diff(scandir($this->directory), ['.', '..'])), 'No temporary file is left behind.');
    }

    public function testOnlyTheBotsUserCanReadTheMemories(): void
    {
        // Whatever the bot was started with: other files it creates may be readable by everyone.
        $umask = umask(0022);

        try {
            $memory = new Memory($this->directory);
            $memory->save('555', '- Likes tea.');
            $memory->save('555', '- Likes coffee now.');
        } finally {
            umask($umask);
        }

        $this->assertSame('0600', substr(sprintf('%o', fileperms("{$this->directory}/555.md")), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->directory)), -4));
    }

    public function testCutsAMemoryThatIsTooLongAfterItsLastLineThatFits(): void
    {
        $lines = array_map(fn (int $fact) => sprintf('- Fact %03d: %sand that is all.', $fact, str_repeat('and so on, ', 7)), range(1, 60));
        $tooLong = implode("\n", $lines);

        $saved = (new Memory($this->directory))->save('555', $tooLong);

        $this->assertSame(4000, Memory::LIMIT);
        $this->assertSame(implode("\n", array_slice($lines, 0, 37)), $saved);
        $this->assertSame(3921, mb_strlen($saved));
        $this->assertSame($saved, (new Memory($this->directory))->read('555'));
    }

    public function testKeepsAMemoryThatIsExactlyAsLongAsTheLimit(): void
    {
        // Counted in characters, not bytes.
        $memory = str_repeat('é', Memory::LIMIT);

        $this->assertSame($memory, (new Memory($this->directory))->save('555', $memory));
    }

    public function testForgetsAPerson(): void
    {
        $memory = new Memory($this->directory);
        $memory->save('555', '- Likes tea.');
        $memory->save('666', '- Likes coffee.');

        $this->assertTrue($memory->forget('555'));

        $this->assertSame('', $memory->read('555'));
        $this->assertFileDoesNotExist("{$this->directory}/555.md");
        $this->assertSame('- Likes coffee.', $memory->read('666'));
        $this->assertFalse($memory->forget('555'), 'There is nothing left to forget.');
    }

    #[TestWith(['../555'])]
    #[TestWith(['555/../../secrets'])]
    #[TestWith([''])]
    public function testOnlyAcceptsDiscordIds(string $userId): void
    {
        $memory = new Memory($this->directory);

        foreach (['read', 'forget'] as $method) {
            try {
                $memory->{$method}($userId);
                $this->fail("{$method}() should have refused the ID.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame("Not a Discord user ID: {$userId}", $e->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);

        $memory->save($userId, '- Likes tea.');
    }

    public function testKeepsTheMemoriesWhereMemoryPathSays(): void
    {
        $this->assertSame('memories', Memory::fromEnv()->directory);

        $_ENV['MEMORY_PATH'] = '/var/lib/bot/memories/';

        $this->assertSame('/var/lib/bot/memories', Memory::fromEnv()->directory);
    }

    public function testRemembersAGroupInOneFileNamedAfterItsPeople(): void
    {
        $umask = umask(0022);

        try {
            $memory = new Memory($this->directory);
            $memory->save('555', '- Likes tea.');
            // The order the people are given in, and who is given twice, change nothing.
            $this->assertSame('- Plan a trip.', $memory->save(['666', '555', '666'], '- Plan a trip.'));
        } finally {
            umask($umask);
        }

        $this->assertSame('- Plan a trip.', $memory->read(['555', '666']));
        $this->assertSame("- Plan a trip.\n", file_get_contents("{$this->directory}/groups/555-666.md"));
        $this->assertSame('- Likes tea.', $memory->read('555'), 'Nobody has their own memory replaced by a group\'s.');
        $this->assertSame('', $memory->read('666'));
        $this->assertSame('0600', substr(sprintf('%o', fileperms("{$this->directory}/groups/555-666.md")), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms("{$this->directory}/groups")), -4));
        $this->assertSame(['555-666.md'], array_values(array_diff(scandir("{$this->directory}/groups"), ['.', '..'])), 'No temporary file is left behind.');
    }

    public function testAGroupOfOnePersonIsThatPersonsOwnMemory(): void
    {
        $memory = new Memory($this->directory);

        $memory->save(['555', '555'], '- Likes tea.');

        $this->assertSame('- Likes tea.', $memory->read('555'));
        $this->assertDirectoryDoesNotExist("{$this->directory}/groups");
    }

    public function testNamesAGroupAfterItsPeopleFromTheLowestId(): void
    {
        // IDs grow with time and get longer, so 999 is a lower ID than 1234, but sorts after it as text.
        $this->assertSame(['999', '1234', '1000000000000000001'], Memory::people(['1000000000000000001', '1234', '999']));

        (new Memory($this->directory))->save(['1000000000000000001', '1234', '999'], '- Run a club.');

        $this->assertFileExists("{$this->directory}/groups/999-1234-1000000000000000001.md");
    }

    public function testForgetsAGroup(): void
    {
        $memory = new Memory($this->directory);
        $memory->save(['555', '666'], '- Plan a trip.');
        $memory->save(['555', '666', '777'], '- Run a club.');

        $this->assertTrue($memory->forget(['666', '555']));

        $this->assertSame('', $memory->read(['555', '666']));
        $this->assertSame('- Run a club.', $memory->read(['555', '666', '777']));
        $this->assertFalse($memory->forget(['555', '666']), 'There is nothing left to forget.');
    }

    public function testListsTheGroupsOfAPerson(): void
    {
        $memory = new Memory($this->directory);
        $this->assertSame([], $memory->groups('555'), 'There is no folder yet.');

        $memory->save(['555', '666', '777'], '- Run a club.');
        $memory->save(['666', '777'], '- Share a flat.');
        $memory->save(['555', '666'], '- Plan a trip.');
        $memory->save(['5', '1000000000000000001'], '- Meet on Sundays.');
        $memory->save('555', '- Likes tea.');
        // Files the bot didn't save are not groups: not named after IDs in order, a person twice, or one person.
        foreach (['notes.md', '666-555.md', '555-555.md', '555-.md', '555-666.txt', '555.md'] as $name) {
            touch("{$this->directory}/groups/{$name}");
        }

        // The smallest groups first.
        $this->assertSame([['555', '666'], ['555', '666', '777']], $memory->groups('555'));
        $this->assertSame([['555', '666'], ['666', '777'], ['555', '666', '777']], $memory->groups('666'));
        $this->assertSame([['5', '1000000000000000001']], $memory->groups('5'), 'Not the groups of 555.');
        $this->assertSame([], $memory->groups('888'));
    }

    public function testOnlyAcceptsDiscordIdsInAGroup(): void
    {
        $memory = new Memory($this->directory);

        foreach ([['555', '../666'], ['555', '']] as $people) {
            try {
                $memory->read($people);
                $this->fail('read() should have refused the ID.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Not a Discord user ID: ' . $people[1], $e->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A memory belongs to somebody.');

        $memory->read([]);
    }
}
