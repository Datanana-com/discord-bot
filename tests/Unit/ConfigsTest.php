<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ConfigsTest extends TestCase
{
    public function testDatabaseConfigHasNoConnectionsByDefault(): void
    {
        require_once dirname(__DIR__, 2) . '/configs/database.php';

        // bootstrap.php adds one database connection per entry.
        $this->assertSame(['connections' => []], databaseConfigs());
    }
}
