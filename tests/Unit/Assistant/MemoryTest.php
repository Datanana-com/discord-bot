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

    public function testCutsALongParagraphAfterItsLastSentenceThatFits(): void
    {
        $paragraph = trim(str_repeat('Alex is building a game called Bananas with two friends. ', 85));
        $tooLong = "## About Alex\n{$paragraph}";

        $saved = (new Memory($this->directory))->save('555', $tooLong);

        // Not only the heading: the paragraph is kept up to its last sentence that fits.
        $this->assertSame(mb_substr($tooLong, 0, mb_strlen($saved)), $saved);
        $this->assertStringEndsWith('two friends.', $saved);
        $this->assertSame(3946, mb_strlen($saved));
    }

    public function testCutsAWordThatIsTooLongAtTheLimit(): void
    {
        $this->assertSame(str_repeat('a', Memory::LIMIT), (new Memory($this->directory))->save('555', str_repeat('a', Memory::LIMIT + 10)));
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

        // Not the root of the disk.
        $_ENV['MEMORY_PATH'] = '';

        $this->assertSame('memories', Memory::fromEnv()->directory);
    }
}
