<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Analytics\Usage;
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
            'failures' => 1,
        ], $summary);
        $this->assertSame([], $this->logs->getRecords());
    }

    public function testAServerWithoutUsageHasNothingToShow(): void
    {
        $this->assertSame(
            ['since' => null, 'calls' => 0, 'call_ms' => 0, 'speakers' => 0, 'utterances' => 0, 'speech_ms' => 0, 'answers' => 0, 'answer_ms' => null, 'failures' => 0],
            $this->usage->summary('100'),
        );
    }

    public function testLogsWhenTheStatisticsAreUnavailable(): void
    {
        $this->breakStatsDatabase();

        $this->usage->record(Usage::CALL_STARTED, '100');
        $this->assertNull($this->usage->summary('100'));

        $this->assertSame(
            ['Could not save usage statistics: Database connection [stats] not configured.', 'Could not read usage statistics: Database connection [stats] not configured.'],
            array_map(fn ($record) => $record->message, $this->logs->getRecords()),
        );
        $this->assertSame(['type' => 'call_started', 'guild' => '100'], $this->logs->getRecords()[0]->context);
    }
}
