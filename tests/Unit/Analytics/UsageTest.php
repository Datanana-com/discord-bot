<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Analytics\Usage;
use Illuminate\Database\Capsule\Manager as DB;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Tests\UsesStatsDatabase;

final class UsageTest extends TestCase
{
    use UsesStatsDatabase;

    private TestHandler $logs;

    private Usage $usage;

    protected function setUp(): void
    {
        $this->useStatsDatabase();
        $this->logs = new TestHandler();
        $this->usage = new Usage(new Logger('test', [$this->logs]));
    }

    public function testSumsUpAServersUsage(): void
    {
        // Two calls in server 100: Alice and Bob talk, Claude answers Alice twice and fails once.
        $this->usage->record(Usage::CALL_STARTED, '100', ['channel' => '200', 'session' => 'a']);
        $this->usage->record(Usage::UTTERANCE, '100', ['user' => 'alice', 'session' => 'a', 'duration_ms' => 2000]);
        $this->usage->record(Usage::ANSWERED, '100', ['user' => 'alice', 'session' => 'a', 'duration_ms' => 4000]);
        $this->usage->record(Usage::UTTERANCE, '100', ['user' => 'bob', 'session' => 'a', 'duration_ms' => 1500]);
        $this->usage->record(Usage::CALL_ENDED, '100', ['session' => 'a', 'duration_ms' => 60000]);
        $this->usage->record(Usage::CALL_STARTED, '100', ['channel' => '200', 'session' => 'b']);
        $this->usage->record(Usage::UTTERANCE, '100', ['user' => 'alice', 'session' => 'b', 'duration_ms' => 3000]);
        $this->usage->record(Usage::ANSWERED, '100', ['user' => 'alice', 'session' => 'b', 'duration_ms' => 7000]);
        $this->usage->record(Usage::UTTERANCE, '100', ['user' => 'alice', 'session' => 'b', 'duration_ms' => 1000]);
        $this->usage->record(Usage::LOOKED_UP, '100', ['user' => 'alice', 'session' => 'b']);
        $this->usage->record(Usage::FAILED, '100', ['user' => 'alice', 'session' => 'b']);
        $this->usage->record(Usage::CALL_ENDED, '100', ['session' => 'b', 'duration_ms' => 30000]);
        // Another server's call doesn't count.
        $this->usage->record(Usage::CALL_STARTED, '999', ['channel' => '900', 'session' => 'c']);

        $summary = $this->usage->summary('100');

        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $summary['since']);
        unset($summary['since']);
        $this->assertSame([
            'calls' => 2,
            'call_ms' => 90000,
            'speakers' => 2,
            'utterances' => 4,
            'speech_ms' => 7500,
            'answers' => 2,
            'answer_ms' => 5500,
            'lookups' => 1,
            'failures' => 1,
        ], $summary);
        $this->assertSame([], $this->logs->getRecords());
    }

    public function testAServerWithoutUsageHasNothingToShow(): void
    {
        $this->assertSame(
            ['since' => null, 'calls' => 0, 'call_ms' => 0, 'speakers' => 0, 'utterances' => 0, 'speech_ms' => 0, 'answers' => 0, 'answer_ms' => null, 'lookups' => 0, 'failures' => 0],
            $this->usage->summary('100'),
        );
        // Reading is not writing: the table is made by the first write, not by /stats on a new database.
        $this->assertFalse(DB::connection(Usage::CONNECTION)->getSchemaBuilder()->hasTable('events'));
    }

    public function testWritesNothingUntilItIsToldToAndThenEverythingInTheOrderItHappened(): void
    {
        $this->usage->record(Usage::CALL_STARTED, '100', ['channel' => '200', 'session' => 'a']);
        $this->usage->record(Usage::UTTERANCE, '100', ['user' => 'alice', 'session' => 'a', 'duration_ms' => 2000]);
        $this->usage->record(Usage::ANSWERED, '100', ['user' => 'alice', 'session' => 'a', 'duration_ms' => 4000]);

        // A write blocks the event loop: it is for the moment nobody waits for the bot.
        $this->assertSame([], $this->writtenEvents(), 'Nothing is written when something is recorded.');

        // Another instance flushes what this one recorded: /stats and each call have their own.
        (new Usage(new Logger('other')))->flush();

        $this->assertSame(['call_started', 'utterance', 'answered'], $this->writtenEvents());
        $rows = DB::connection(Usage::CONNECTION)->table('events')->orderBy('id')->get();
        $this->assertSame(['100', '200', null, 'a', null], [$rows[0]->guild_id, $rows[0]->channel_id, $rows[0]->user_id, $rows[0]->session_id, $rows[0]->duration_ms]);
        $this->assertSame(['alice', 2000, 4000], [$rows[1]->user_id, $rows[1]->duration_ms, $rows[2]->duration_ms]);

        // Written once: the next flush has nothing left to write.
        $this->usage->flush();
        $this->assertCount(3, $this->writtenEvents());
        $this->assertSame([], $this->logs->getRecords());
    }

    public function testKeepsTheTimeSomethingHappenedAndNotTheTimeItWasWritten(): void
    {
        $this->usage->record(Usage::UTTERANCE, '100', ['user' => 'alice', 'duration_ms' => 2000]);
        sleep(2);
        $this->usage->flush();

        $written = DB::connection(Usage::CONNECTION)->table('events')->value('created_at');
        $ago = time() - strtotime("{$written} UTC");
        $this->assertGreaterThanOrEqual(2, $ago, 'The row has the time of the utterance, two seconds before it was written.');
        $this->assertLessThan(10, $ago, 'And that is the time of the utterance, not some other.');
    }

    public function testWritesALongListInOrderInStatementsThatFit(): void
    {
        // SQLite refuses a statement with more than 999 values, in the versions of it that PHP may come with: 100 rows of 7.
        DB::connection(Usage::CONNECTION)->enableQueryLog();

        for ($i = 0; $i < 250; $i++) {
            $this->usage->record(Usage::UTTERANCE, '100', ['user' => "user{$i}", 'duration_ms' => $i]);
        }

        $this->usage->flush();

        $this->assertSame(
            range(0, 249),
            DB::connection(Usage::CONNECTION)->table('events')->orderBy('id')->pluck('duration_ms')->all(),
        );
        $inserts = array_filter(DB::connection(Usage::CONNECTION)->getQueryLog(), fn (array $query) => str_starts_with($query['query'], 'insert into "events"'));
        $this->assertSame([700, 700, 350], array_values(array_map(fn (array $query) => count($query['bindings']), $inserts)));
        $this->assertSame([], $this->logs->getRecords());
    }

    public function testWritesNothingOfAListThatFailsHalfWay(): void
    {
        // The table exists, with a rule that refuses the last row, which is in the second INSERT.
        $this->usage->record(Usage::CALL_STARTED, '100');
        $this->usage->flush();
        DB::connection(Usage::CONNECTION)->statement("CREATE TRIGGER refuses BEFORE INSERT ON events WHEN NEW.duration_ms = 999 BEGIN SELECT RAISE(ABORT, 'refused'); END");

        for ($i = 0; $i < 100; $i++) {
            $this->usage->record(Usage::UTTERANCE, '100', ['duration_ms' => $i]);
        }

        $this->usage->record(Usage::UTTERANCE, '100', ['duration_ms' => 999]);
        $this->usage->flush();
        // And not again: what couldn't be written is let go.
        $this->usage->flush();

        $this->assertSame(['call_started'], $this->writtenEvents(), 'Either all of them are written or none: only the one before is.');
        $records = $this->logs->getRecords();
        $this->assertCount(1, $records);
        $this->assertStringStartsWith('Could not save usage statistics: ', $records[0]->message);
        $this->assertStringContainsString('refused', $records[0]->message);
        $this->assertSame(['rows' => 101], $records[0]->context);
    }

    public function testLogsWhenTheStatisticsAreUnavailable(): void
    {
        $this->breakStatsDatabase();

        $this->usage->record(Usage::CALL_STARTED, '100');
        $this->usage->flush();
        $this->assertNull($this->usage->summary('100'));

        $this->assertSame(
            ['Could not save usage statistics: Database connection [stats] not configured.', 'Could not read usage statistics: Database connection [stats] not configured.'],
            array_map(fn ($record) => $record->message, $this->logs->getRecords()),
        );
        $this->assertSame(['rows' => 1], $this->logs->getRecords()[0]->context);
    }

    public function testCountsWhatIsHeldWithoutWritingIt(): void
    {
        // Written: a call of 100 s with Alice speaking, and one of server 999. Held: Alice and Bob speak, one answer.
        $this->usage->record(Usage::CALL_STARTED, '100');
        $this->usage->record(Usage::UTTERANCE, '100', ['user' => 'alice', 'duration_ms' => 1000]);
        $this->usage->record(Usage::UTTERANCE, '999', ['user' => 'zoe', 'duration_ms' => 9000]);
        $this->usage->flush();
        $this->usage->record(Usage::UTTERANCE, '100', ['user' => 'alice', 'duration_ms' => 2000]);
        // Twice the same, in the same second: two utterances.
        $this->usage->record(Usage::UTTERANCE, '100', ['user' => 'bob', 'duration_ms' => 3000]);
        $this->usage->record(Usage::UTTERANCE, '100', ['user' => 'bob', 'duration_ms' => 3000]);
        $this->usage->record(Usage::ANSWERED, '100', ['user' => 'bob', 'duration_ms' => 4000]);
        $this->usage->record(Usage::FAILED, '999', ['user' => 'zoe']);

        $summary = $this->usage->summary('100');

        // /stats may be asked while an answer is spoken, and that is no time to write.
        $this->assertSame(['call_started', 'utterance', 'utterance'], $this->writtenEvents());
        unset($summary['since']);
        $this->assertSame(
            ['calls' => 1, 'call_ms' => 0, 'speakers' => 2, 'utterances' => 4, 'speech_ms' => 9000, 'answers' => 1, 'answer_ms' => 4000, 'lookups' => 0, 'failures' => 0],
            $summary,
        );

        // And the same once it is.
        $this->usage->flush();
        $written = $this->usage->summary('100');
        unset($written['since']);
        $this->assertSame($summary, $written);
    }

    public function testASummaryOfWhatIsOnlyHeldStartsAtTheFirstOfThem(): void
    {
        $before = gmdate('Y-m-d H:i:s');
        $this->usage->record(Usage::CALL_STARTED, '100');
        $after = gmdate('Y-m-d H:i:s');

        $summary = $this->usage->summary('100');

        $this->assertGreaterThanOrEqual($before, $summary['since']);
        $this->assertLessThanOrEqual($after, $summary['since']);
        $this->assertSame(1, $summary['calls']);
    }

    public function testDoesNotTouchTheDatabaseWhenNothingIsHeld(): void
    {
        $this->usage->flush();
        $this->assertSame([], $this->writtenEvents());
        $this->assertFalse(DB::connection(Usage::CONNECTION)->getSchemaBuilder()->hasTable('events'), 'Not even the table is made.');

        $this->breakStatsDatabase();
        $this->usage->flush();
        $this->assertSame([], $this->logs->getRecords());
    }

    public function testForgetsWhatIsHeldWhenToldToReset(): void
    {
        $this->usage->record(Usage::CALL_STARTED, '100');

        Usage::reset();
        $this->usage->flush();

        $this->assertSame(0, $this->usage->summary('100')['calls']);
        $this->assertSame([], $this->writtenEvents());
    }
}
