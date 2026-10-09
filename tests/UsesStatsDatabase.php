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
        // Rows a test left held would be written into this test's database.
        Usage::reset();
    }

    /**
     * What is written in the statistics database, in the order it was written: not what is held and not yet.
     *
     * @return list<string> Each event's type.
     */
    protected function writtenEvents(): array
    {
        $connection = DB::connection(Usage::CONNECTION);

        return $connection->getSchemaBuilder()->hasTable('events')
            ? $connection->table('events')->orderBy('id')->pluck('type')->all()
            : [];
    }

    /**
     * Makes the statistics unavailable, as when the database can't be opened.
     */
    protected function breakStatsDatabase(): void
    {
        (new DB())->setAsGlobal();
    }
}
