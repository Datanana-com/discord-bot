<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Analytics\Usage;
use App\Commands\Global\StatsCommand;
use Monolog\Logger;

final class StatsCommandTest extends CommandTestCase
{
    public function testShowsTheServersUsageToWhoeverAsked(): void
    {
        $usage = new Usage(new Logger('test'));
        $usage->record(Usage::CALL_STARTED, self::GUILD_ID, ['channel' => '200']);
        $usage->record(Usage::UTTERANCE, self::GUILD_ID, ['user' => '555', 'duration_ms' => 65000]);
        $usage->record(Usage::UTTERANCE, self::GUILD_ID, ['user' => '666', 'duration_ms' => 4000]);
        $usage->record(Usage::ANSWERED, self::GUILD_ID, ['user' => '555', 'duration_ms' => 6200]);
        $usage->record(Usage::LOOKED_UP, self::GUILD_ID, ['user' => '555']);
        $usage->record(Usage::LOOKED_UP, self::GUILD_ID, ['user' => '666']);
        $usage->record(Usage::FAILED, self::GUILD_ID, ['user' => '666']);
        $usage->record(Usage::CALL_ENDED, self::GUILD_ID, ['duration_ms' => 3725000]);

        (new StatsCommand($this->discord))->handle($this->interaction(null));

        $this->assertCount(1, $this->responses);
        $this->assertTrue($this->responses[0]['ephemeral'], 'Only whoever asked sees it.');
        [$title, $report] = explode("\n", $this->responses[0]['content'], 2);
        $this->assertMatchesRegularExpression('/^\*\*Usage in this server\*\* since <t:(\d+):D>$/', $title);
        $this->assertEqualsWithDelta(time(), (int) preg_replace('/\D/', '', $title), 60, 'Since the first event, shown in each reader\'s time zone.');
        $this->assertSame(
            "Calls recorded: 1 (1 h 2 min)\nSpeech: 2 utterances from 2 people (1 min)\nQuestions answered: 1, in 6.2 s on average\nLooked up: 2\nFailures: 1",
            $report,
        );
    }

    public function testLeavesOutTheAnswerTimeWhenNothingWasAnswered(): void
    {
        $usage = new Usage(new Logger('test'));
        $usage->record(Usage::CALL_STARTED, self::GUILD_ID, ['channel' => '200']);
        $usage->record(Usage::CALL_ENDED, self::GUILD_ID, ['duration_ms' => 45000]);

        (new StatsCommand($this->discord))->handle($this->interaction(null));

        $this->assertStringEndsWith(
            "Calls recorded: 1 (45 s)\nSpeech: 0 utterances from 0 people (0 s)\nQuestions answered: 0\nLooked up: 0\nFailures: 0",
            $this->responses[0]['content'],
        );
    }

    public function testSaysWhenNothingWasRecordedYet(): void
    {
        (new StatsCommand($this->discord))->handle($this->interaction(null));

        $this->assertSame([['content' => 'Nothing has been recorded in this server yet.', 'ephemeral' => true]], $this->responses);
    }

    public function testOnlyWorksInAServer(): void
    {
        (new StatsCommand($this->discord))->handle($this->interaction(null, guildId: null));

        $this->assertSame([['content' => 'Use /stats in a server.', 'ephemeral' => true]], $this->responses);
    }

    public function testSaysWhenTheStatisticsAreUnavailable(): void
    {
        $this->breakStatsDatabase();

        (new StatsCommand($this->discord))->handle($this->interaction(null));

        $this->assertSame([['content' => 'The statistics are not available right now. Check the bot logs.', 'ephemeral' => true]], $this->responses);
        $this->assertSame(['Could not read usage statistics: Database connection [stats] not configured.'], $this->loggedProblems());
    }
}
