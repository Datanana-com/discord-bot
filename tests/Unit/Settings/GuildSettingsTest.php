<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use App\Analytics\Usage;
use App\Settings\GuildSettings;
use Illuminate\Database\Capsule\Manager as DB;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Tests\UsesStatsDatabase;

final class GuildSettingsTest extends TestCase
{
    use UsesStatsDatabase;

    private TestHandler $logs;

    private GuildSettings $settings;

    protected function setUp(): void
    {
        $this->useStatsDatabase();
        $this->logs = new TestHandler();
        $this->settings = new GuildSettings(new Logger('test', [$this->logs]));
    }

    public function testAServerThatChangedNothingUsesEveryDefault(): void
    {
        // Null is the .env default.
        $this->assertSame(['wake_word' => null, 'language' => null, 'voice' => null, 'model' => null], $this->settings->find('100'));
        $this->assertSame(GuildSettings::DEFAULTS, $this->settings->for('100'));
        $this->assertSame([], $this->logs->getRecords());
    }

    public function testKeepsEachServersSettings(): void
    {
        $this->assertTrue($this->settings->save('100', ['wake_word' => 'jarvis', 'language' => 'pt', 'voice' => 'pt_BR-faber-medium', 'model' => 'sonnet'], '555'));
        $this->assertTrue($this->settings->save('999', [...GuildSettings::DEFAULTS, 'wake_word' => ''], '777'));

        $this->assertSame(['wake_word' => 'jarvis', 'language' => 'pt', 'voice' => 'pt_BR-faber-medium', 'model' => 'sonnet'], $this->settings->find('100'));
        // An empty wake word answers everything, unlike null, which is the default wake word.
        $this->assertSame(['wake_word' => '', 'language' => null, 'voice' => null, 'model' => null], $this->settings->for('999'));
        $this->assertSame(GuildSettings::DEFAULTS, $this->settings->find('101'));

        // They are in the database, so they outlive the bot restarting.
        $this->assertSame('pt', (new GuildSettings(new Logger('test')))->find('100')['language']);
        $this->assertSame([], $this->logs->getRecords());
    }

    public function testSavingAgainReplacesTheSettingsAndSaysWhoChangedThem(): void
    {
        // Wherever the bot runs, the time is saved in UTC, like the statistics.
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');

        try {
            $this->settings->save('100', [...GuildSettings::DEFAULTS, 'language' => 'pt', 'model' => 'opus'], '555');
            $this->settings->save('100', [...GuildSettings::DEFAULTS, 'model' => 'sonnet'], '666');
        } finally {
            date_default_timezone_set($timezone);
        }

        $this->assertSame(['wake_word' => null, 'language' => null, 'voice' => null, 'model' => 'sonnet'], $this->settings->find('100'));

        $rows = DB::connection(Usage::CONNECTION)->table('guild_settings')->get();
        $this->assertCount(1, $rows, 'A server has one row.');
        $this->assertSame('666', $rows[0]->updated_by);
        $this->assertEqualsWithDelta(time(), strtotime("{$rows[0]->updated_at} UTC"), 60, 'Changed just now, in UTC.');
    }

    public function testKeepsThemInTheStatisticsDatabase(): void
    {
        $this->settings->find('100');

        // The table is created the first time it is needed, next to the usage statistics.
        $this->assertSame(
            ['guild_id', 'wake_word', 'language', 'voice', 'model', 'updated_by', 'updated_at'],
            DB::connection(Usage::CONNECTION)->getSchemaBuilder()->getColumnListing('guild_settings'),
        );

        // The database itself keeps a server to one row.
        $primaryKeys = array_filter(
            DB::connection(Usage::CONNECTION)->getSchemaBuilder()->getIndexes('guild_settings'),
            fn (array $index) => $index['primary'],
        );
        $this->assertSame([['guild_id']], array_column($primaryKeys, 'columns'));
    }

    public function testLogsWhenTheSettingsAreUnavailable(): void
    {
        $this->breakStatsDatabase();

        $this->assertNull($this->settings->find('100'));
        // A call still starts, with the .env defaults.
        $this->assertSame(GuildSettings::DEFAULTS, $this->settings->for('100'));
        $this->assertFalse($this->settings->save('100', [...GuildSettings::DEFAULTS, 'language' => 'pt'], '555'));

        $this->assertSame(
            [
                'Could not read the server settings: Database connection [stats] not configured.',
                'Could not read the server settings: Database connection [stats] not configured.',
                'Could not save the server settings: Database connection [stats] not configured.',
            ],
            array_map(fn ($record) => $record->message, $this->logs->getRecords()),
        );

        foreach ($this->logs->getRecords() as $record) {
            $this->assertSame(Level::Warning, $record->level);
            $this->assertSame(['guild' => '100'], $record->context);
        }
    }
}
