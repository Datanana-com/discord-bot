<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use App\Analytics\Usage;
use App\Settings\UserSettings;
use Illuminate\Database\Capsule\Manager as DB;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Tests\UsesStatsDatabase;

final class UserSettingsTest extends TestCase
{
    use UsesStatsDatabase;

    private TestHandler $logs;

    private UserSettings $settings;

    protected function setUp(): void
    {
        $this->useStatsDatabase();
        $this->logs = new TestHandler();
        $this->settings = new UserSettings(new Logger('test', [$this->logs]));
    }

    public function testSomeoneWhoChoseNothingHasTheDefaults(): void
    {
        // The personal memory is used when its owner asks, as it was before /privacy.
        $this->assertSame(['personal_memory_in_calls' => 'when_asked'], $this->settings->find('555'));
        $this->assertSame(UserSettings::DEFAULTS, $this->settings->find('555'));
        $this->assertSame([], $this->logs->getRecords());
    }

    public function testKeepsEachPersonsSettings(): void
    {
        $this->assertTrue($this->settings->save('555', ['personal_memory_in_calls' => UserSettings::AFTER_SHARE]));

        $this->assertSame(['personal_memory_in_calls' => 'after_share'], $this->settings->find('555'));
        $this->assertSame(UserSettings::DEFAULTS, $this->settings->find('666'), 'Someone else is not affected.');

        // They are in the database, so they outlive the bot restarting.
        $this->assertSame('after_share', (new UserSettings(new Logger('test')))->find('555')['personal_memory_in_calls']);
        $this->assertSame([], $this->logs->getRecords());
    }

    public function testSavingAgainReplacesTheSettingAndSaysWhenItChanged(): void
    {
        // Wherever the bot runs, the time is saved in UTC, like the statistics.
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');

        try {
            $this->settings->save('555', ['personal_memory_in_calls' => UserSettings::AFTER_SHARE]);
            $this->settings->save('555', ['personal_memory_in_calls' => UserSettings::WHEN_ASKED]);
        } finally {
            date_default_timezone_set($timezone);
        }

        $this->assertSame(UserSettings::DEFAULTS, $this->settings->find('555'));

        $rows = DB::connection(Usage::CONNECTION)->table('user_settings')->get();
        $this->assertCount(1, $rows, 'A person has one row.');
        $this->assertEqualsWithDelta(time(), strtotime("{$rows[0]->updated_at} UTC"), 60, 'Changed just now, in UTC.');
    }

    public function testKeepsThemInTheStatisticsDatabase(): void
    {
        $this->settings->find('555');

        // The table is created the first time it is needed, next to the usage statistics.
        $this->assertSame(
            ['user_id', 'personal_memory_in_calls', 'updated_at'],
            DB::connection(Usage::CONNECTION)->getSchemaBuilder()->getColumnListing('user_settings'),
        );

        // The database itself keeps a person to one row.
        $primaryKeys = array_filter(
            DB::connection(Usage::CONNECTION)->getSchemaBuilder()->getIndexes('user_settings'),
            fn (array $index) => $index['primary'],
        );
        $this->assertSame([['user_id']], array_column($primaryKeys, 'columns'));
    }

    public function testASettingThatIsNotAChoiceCannotBeRead(): void
    {
        $this->settings->save('555', ['personal_memory_in_calls' => UserSettings::AFTER_SHARE]);
        DB::connection(Usage::CONNECTION)->table('user_settings')->update(['personal_memory_in_calls' => 'whenever']);

        // Nothing says what the person wanted, so nothing is guessed: the caller keeps what is private private.
        $this->assertNull($this->settings->find('555'));
        $this->assertSame(['The user settings hold a value that is not a choice.'], array_map(fn ($record) => $record->message, $this->logs->getRecords()));
        $this->assertSame(['user' => '555'], $this->logs->getRecords()[0]->context);
        $this->assertSame(Level::Warning, $this->logs->getRecords()[0]->level);
    }

    public function testLogsWhenTheSettingsAreUnavailable(): void
    {
        $this->breakStatsDatabase();

        // There is no default to fall back to: it is not known what the person chose.
        $this->assertNull($this->settings->find('555'));
        $this->assertFalse($this->settings->save('555', ['personal_memory_in_calls' => UserSettings::AFTER_SHARE]));

        $this->assertSame(
            [
                'Could not read the user settings: Database connection [stats] not configured.',
                'Could not save the user settings: Database connection [stats] not configured.',
            ],
            array_map(fn ($record) => $record->message, $this->logs->getRecords()),
        );

        foreach ($this->logs->getRecords() as $record) {
            $this->assertSame(Level::Warning, $record->level);
            $this->assertSame(['user' => '555'], $record->context);
        }
    }
}
