<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ConfigsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['STATS_DATABASE']);
    }

    public function testStatisticsAreKeptInSqlite(): void
    {
        require_once dirname(__DIR__, 2) . '/configs/database.php';

        // bootstrap.php adds one database connection per entry.
        $this->assertSame(
            ['stats' => ['driver' => 'sqlite', 'database' => 'databases/stats.sqlite', 'foreign_key_constraints' => true]],
            databaseConfigs()['connections'],
        );

        $_ENV['STATS_DATABASE'] = '/var/lib/discord-bot/stats.sqlite';
        $this->assertSame('/var/lib/discord-bot/stats.sqlite', databaseConfigs()['connections']['stats']['database']);
    }
}
