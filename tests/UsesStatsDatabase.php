<?php

declare(strict_types=1);

namespace Tests;

use App\Analytics\Usage;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Gives the usage statistics a fresh in-memory database, set up like bootstrap.php sets up the real one.
 */
trait UsesStatsDatabase
{
    protected function useStatsDatabase(): void
    {
        $capsule = new DB();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], Usage::CONNECTION);
        $capsule->setAsGlobal();
    }

    /**
     * Makes the statistics unavailable, as when the database can't be opened.
     */
    protected function breakStatsDatabase(): void
    {
        (new DB())->setAsGlobal();
    }
}
