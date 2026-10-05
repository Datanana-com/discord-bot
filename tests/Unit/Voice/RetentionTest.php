<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Voice\Retention;
use DateTimeImmutable;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class RetentionTest extends TestCase
{
    /** The tests keep recordings for 30 days, so until this, calls from 2026-09-05 12:00:00 on are kept. */
    private const string NOW = '2026-10-05 12:00:00';

    private string $recordings;

    private TestHandler $logs;

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $this->recordings = sys_get_temp_dir() . '/retention-test-' . uniqid();
        mkdir($this->recordings);
        $this->logs = new TestHandler();

        foreach (['RECORDINGS_PATH', 'RECORDINGS_RETENTION_DAYS'] as $name) {
            $this->originalEnv[$name] = $_ENV[$name] ?? null;
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }

        // Folders a test made read-only can be deleted again.
        exec('chmod -R u+w ' . escapeshellarg($this->recordings) . ' && rm -rf ' . escapeshellarg($this->recordings));
    }

    public function testDeletesCallsOlderThanTheNumberOfDays(): void
    {
        $old = $this->call('100', '2026-08-01_09-30-00');
        $justTooOld = $this->call('100', '2026-09-05_11-59-59');
        $justRecentEnough = $this->call('100', '2026-09-05_12-00-00');
        $recent = $this->call('100', '2026-10-04_20-15-00');
        $otherServers = $this->call('200', '2026-08-01_09-30-00');

        $this->assertSame(3, $this->retention()->prune(new DateTimeImmutable(self::NOW)));

        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryDoesNotExist($justTooOld);
        $this->assertDirectoryDoesNotExist($otherServers);
        // What is kept is kept whole.
        $this->assertSame(['555-1.wav', 'claude-2.ogg', 'summary.md', 'transcript.txt', 'utterances'], $this->entries($justRecentEnough));
        $this->assertFileExists("{$justRecentEnough}/utterances/555-1.wav");
        $this->assertSame(['555-1.wav', 'claude-2.ogg', 'summary.md', 'transcript.txt', 'utterances'], $this->entries($recent));
        $this->assertSame([['Deleted old recordings', ['calls' => 3, 'days' => 30]]], $this->logged());
    }

    public function testGoesByTheDateInTheFoldersNameNotByWhenItWasChanged(): void
    {
        // An old call whose folder was just written to, and a recent one whose files look old.
        $old = $this->call('100', '2026-08-01_09-30-00');
        $recent = $this->call('100', '2026-10-04_20-15-00');
        touch($recent, strtotime('2020-01-01'));
        touch("{$recent}/transcript.txt", strtotime('2020-01-01'));

        $this->assertSame(1, $this->retention()->prune(new DateTimeImmutable(self::NOW)));

        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($recent);
    }

    public function testKeepsMoreWhenTheNumberOfDaysIsHigher(): void
    {
        $call = $this->call('100', '2026-08-01_09-30-00');

        $this->assertSame(0, $this->retention(days: 90)->prune(new DateTimeImmutable(self::NOW)));
        $this->assertDirectoryExists($call);
        $this->assertSame([], $this->logged(), 'Nothing is logged when nothing was deleted.');

        $this->assertSame(1, $this->retention(days: 1)->prune(new DateTimeImmutable(self::NOW)));
        $this->assertDirectoryDoesNotExist($call);
        $this->assertSame([['Deleted old recordings', ['calls' => 1, 'days' => 1]]], $this->logged());
    }

    public function testKeepsEverythingForMoreDaysThanADateCanGoBack(): void
    {
        $call = $this->call('100', '1970-01-01_00-00-00');

        // Going back this many days from a date ends up in the future, which would make every call an old one.
        $this->assertSame(0, $this->retention(days: PHP_INT_MAX)->prune(new DateTimeImmutable(self::NOW)));

        $this->assertDirectoryExists($call);
    }

    /**
     * @param string      $path     What is in the recordings folder, relative to it.
     * @param string|null $linkedTo When set, the path is a link to this folder, which holds an old call.
     */
    #[DataProvider('outsideTheLayout')]
    public function testKeepsWhatDoesNotFollowTheLayout(string $path, bool $isFolder = true, ?string $linkedTo = null): void
    {
        $path = "{$this->recordings}/{$path}";
        // The server's folder is never left empty, which would get it removed.
        $this->call('100', '2026-10-04_20-15-00');
        $inside = null;

        if ($linkedTo !== null) {
            $target = "{$this->recordings}/{$linkedTo}";
            mkdir($target, 0755, true);
            $inside = $this->callAt("{$target}/2020-01-01_00-00-00");
            is_dir(dirname($path)) || mkdir(dirname($path), 0755, true);
            symlink($target, $path);
        } elseif ($isFolder) {
            $inside = "{$this->callAt($path)}/transcript.txt";
        } else {
            is_dir(dirname($path)) || mkdir(dirname($path), 0755, true);
            touch($path);
        }

        $this->assertSame(0, $this->retention()->prune(new DateTimeImmutable(self::NOW)));

        $this->assertFileExists($path);
        $this->assertFileExists($inside ?? $path);
        $this->assertSame([], $this->logged());
    }

    /**
     * @return iterable<string, array{0: string, 1?: bool, 2?: string|null}>
     */
    public static function outsideTheLayout(): iterable
    {
        yield 'a file next to the server folders' => ['bot.log', false];
        yield 'the statistics, when they are kept with the recordings' => ['stats.sqlite', false];
        yield 'a file named like a server' => ['200', false];
        yield 'a call outside a server folder' => ['2020-01-01_00-00-00'];
        yield 'a folder that is not a server' => ['models/2020-01-01_00-00-00'];
        yield 'a folder that only starts like a server ID' => ['100a/2020-01-01_00-00-00'];
        yield 'a file named like a call' => ['100/2020-01-01_00-00-00', false];
        yield 'a folder not named after a date' => ['100/notes'];
        yield 'a date without a time' => ['100/2020-01-01'];
        yield 'a date with something after it' => ['100/2020-01-01_00-00-00-copy'];
        yield 'a date with something before it' => ['100/old-2020-01-01_00-00-00'];
        yield 'a date that does not exist' => ['100/2020-13-45_00-00-00'];
        yield 'a time that does not exist' => ['100/2020-01-01_25-00-00'];
        yield 'a date without leading zeros' => ['100/2020-1-1_0-0-0'];
        yield 'a call inside a call' => ['100/2026-10-04_20-15-00/2020-01-01_00-00-00'];
        yield 'a link to a server folder' => ['200', true, 'elsewhere/200'];
        yield 'a link to a call folder' => ['100/2020-01-01_00-00-00', true, 'elsewhere/call'];
    }

    public function testDeletesLinksInACallWithoutWhatTheyPointTo(): void
    {
        $call = $this->call('100', '2026-08-01_09-30-00');
        mkdir("{$this->recordings}/elsewhere");
        touch("{$this->recordings}/elsewhere/kept.txt");
        symlink("{$this->recordings}/elsewhere", "{$call}/link-to-a-folder");
        symlink("{$this->recordings}/elsewhere/kept.txt", "{$call}/link-to-a-file");
        symlink("{$this->recordings}/missing", "{$call}/link-to-nothing");

        $this->assertSame(1, $this->retention()->prune(new DateTimeImmutable(self::NOW)));

        $this->assertDirectoryDoesNotExist($call);
        $this->assertFileExists("{$this->recordings}/elsewhere/kept.txt");
    }

    public function testRemovesServerFoldersLeftEmpty(): void
    {
        $this->call('100', '2026-08-01_09-30-00');
        $this->call('100', '2026-08-02_09-30-00');
        // Server 200's folder was already empty, and server 300's holds something that isn't a call.
        mkdir("{$this->recordings}/200");
        $this->call('300', '2026-08-01_09-30-00');
        touch("{$this->recordings}/300/notes.txt");
        // Not a server's folder, so it stays even though it is empty.
        mkdir("{$this->recordings}/models");

        $this->assertSame(3, $this->retention()->prune(new DateTimeImmutable(self::NOW)));

        $this->assertSame(['300', 'models'], $this->entries($this->recordings));
        $this->assertSame(['notes.txt'], $this->entries("{$this->recordings}/300"));
    }

    public function testKeepsTheRecordingsFolderItselfWhenItIsLeftEmpty(): void
    {
        $this->call('100', '2026-08-01_09-30-00');

        $this->assertSame(1, $this->retention()->prune(new DateTimeImmutable(self::NOW)));

        $this->assertSame([], $this->entries($this->recordings));
    }

    public function testDoesNothingWithoutARecordingsFolder(): void
    {
        // Nothing was recorded yet: the folder is created by the first call.
        $retention = new Retention("{$this->recordings}/missing", 30, new Logger('test', [$this->logs]));

        $this->assertSame(0, $retention->prune(new DateTimeImmutable(self::NOW)));

        $this->assertSame([], $this->logged());
    }

    public function testWarnsWhenACallCannotBeDeleted(): void
    {
        $stuck = $this->call('100', '2026-08-01_09-30-00');
        $deletable = $this->call('100', '2026-08-02_09-30-00');
        // Nothing can be deleted from a read-only folder.
        chmod($stuck, 0555);

        if (is_writable($stuck)) {
            $this->markTestSkipped('Read-only folders do not stop root from deleting.');
        }

        $this->assertSame(1, $this->retention()->prune(new DateTimeImmutable(self::NOW)));

        $this->assertDirectoryExists($stuck);
        $this->assertDirectoryDoesNotExist($deletable);
        // The call that could not be deleted is not counted, and its server's folder stays.
        $this->assertSame([
            ['Could not delete old recordings', ['guild' => '100', 'call' => '2026-08-01_09-30-00']],
            ['Deleted old recordings', ['calls' => 1, 'days' => 30]],
        ], $this->logged());

        // It is deleted the next time, once it can be.
        chmod($stuck, 0755);
        $this->assertSame(1, $this->retention()->prune(new DateTimeImmutable(self::NOW)));
        $this->assertSame([], $this->entries($this->recordings));
    }

    public function testWarnsWhenAFolderInsideACallCannotBeDeleted(): void
    {
        $stuck = $this->call('100', '2026-08-01_09-30-00');
        chmod("{$stuck}/utterances", 0555);

        if (is_writable("{$stuck}/utterances")) {
            $this->markTestSkipped('Read-only folders do not stop root from deleting.');
        }

        $this->assertSame(0, $this->retention()->prune(new DateTimeImmutable(self::NOW)));

        // Everything that could be deleted is gone, which frees most of the space.
        $this->assertSame(['utterances'], $this->entries($stuck));
        $this->assertSame([['Could not delete old recordings', ['guild' => '100', 'call' => '2026-08-01_09-30-00']]], $this->logged());
    }

    public function testKeepsEverythingWhenTheNumberOfDaysIsNotSet(): void
    {
        $_ENV['RECORDINGS_PATH'] = $this->recordings;

        $this->assertNull(Retention::fromEnv(new Logger('test', [$this->logs])), 'Unset.');

        $_ENV['RECORDINGS_RETENTION_DAYS'] = '';

        $this->assertNull(Retention::fromEnv(new Logger('test', [$this->logs])), 'Empty.');
        $this->assertSame([], $this->logged());
    }

    #[DataProvider('invalidDays')]
    public function testKeepsEverythingWhenTheNumberOfDaysIsNotValid(string $days): void
    {
        $_ENV['RECORDINGS_PATH'] = $this->recordings;
        $_ENV['RECORDINGS_RETENTION_DAYS'] = $days;

        $this->assertNull(Retention::fromEnv(new Logger('test', [$this->logs])));

        $this->assertSame(
            [['RECORDINGS_RETENTION_DAYS is not a whole number of days, 1 or more: no recordings are deleted.', []]],
            $this->logged(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDays(): iterable
    {
        // Read as a number, most of these would be 0 days, which deletes every call.
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'not a number' => ['a month'];
        yield 'a number with a unit' => ['30d'];
        yield 'a fraction' => ['1.5'];
        // env() reads these as true, false and null.
        yield 'true' => ['true'];
        yield 'false' => ['false'];
        yield 'null' => ['null'];
    }

    public function testReadsTheFolderAndTheNumberOfDaysFromTheSettings(): void
    {
        $_ENV['RECORDINGS_PATH'] = "{$this->recordings}/";
        $_ENV['RECORDINGS_RETENTION_DAYS'] = '30';
        $old = $this->call('100', '2026-09-05_11-59-59');
        $recent = $this->call('100', '2026-09-05_12-00-00');

        $retention = Retention::fromEnv(new Logger('test', [$this->logs]));

        $this->assertSame(1, $retention?->prune(new DateTimeImmutable(self::NOW)));
        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($recent);
        $this->assertSame([['Deleted old recordings', ['calls' => 1, 'days' => 30]]], $this->logged());
    }

    public function testUsesTheRecordingsFolderInTheProjectByDefault(): void
    {
        $_ENV['RECORDINGS_RETENTION_DAYS'] = '30';

        $log = new Logger('test', [$this->logs]);

        // The same default as where calls are recorded to.
        $this->assertEquals(new Retention('recordings', 30, $log), Retention::fromEnv($log));
    }

    public function testDeletesOldRecordingsNowThenEveryHour(): void
    {
        $everyHour = null;
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addPeriodicTimer')->willReturnCallback(
            function (int|float $interval, callable $callback) use (&$everyHour) {
                $this->assertSame(3600, $interval);
                $everyHour = $callback;

                return static::createStub(TimerInterface::class);
            },
        );
        // Going by the real time, as the bot does.
        $old = $this->call('100', date('Y-m-d_H-i-s', time() - 31 * 86400));
        $recent = $this->call('100', date('Y-m-d_H-i-s', time() - 29 * 86400));

        $this->retention()->start($loop);

        $this->assertDirectoryDoesNotExist($old, 'Old recordings are deleted right away.');
        $this->assertDirectoryExists($recent);

        // A call that got old since then is deleted by the next check.
        $oldByNow = $this->call('200', date('Y-m-d_H-i-s', time() - 31 * 86400));
        $everyHour();

        $this->assertDirectoryDoesNotExist($oldByNow);
        $this->assertDirectoryExists($recent);
        $this->assertSame([
            ['Deleted old recordings', ['calls' => 1, 'days' => 30]],
            ['Deleted old recordings', ['calls' => 1, 'days' => 30]],
        ], $this->logged());
    }

    private function retention(int $days = 30): Retention
    {
        return new Retention($this->recordings, $days, new Logger('test', [$this->logs]));
    }

    /**
     * Makes a call's folder, with what a call leaves in it.
     *
     * @return string The folder's path.
     */
    private function call(string $guildId, string $startedAt): string
    {
        return $this->callAt("{$this->recordings}/{$guildId}/{$startedAt}");
    }

    private function callAt(string $path): string
    {
        mkdir("{$path}/utterances", 0755, true);

        foreach (['555-1.wav', 'claude-2.ogg', 'transcript.txt', 'summary.md', 'utterances/555-1.wav'] as $file) {
            file_put_contents("{$path}/{$file}", 'audio or text');
        }

        return $path;
    }

    /**
     * @return list<string> The names of what is in a folder.
     */
    private function entries(string $path): array
    {
        return array_values(array_diff(scandir($path), ['.', '..']));
    }

    /**
     * @return list<array{string, array<string, mixed>}> What was logged, with its context.
     */
    private function logged(): array
    {
        return array_map(fn ($record) => [$record->message, $record->context], $this->logs->getRecords());
    }
}
