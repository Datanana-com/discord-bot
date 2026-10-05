<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Analytics\Usage;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;

/**
 * The people who opted out of being recorded, with /optout: one row each, in the "stats" database
 * from configs/database.php. An opt-out counts in every server.
 *
 * Unlike the usage statistics, this is about privacy, so a failure is never hidden: every method
 * throws when the database can't be used, and the caller decides what is safe to do then.
 */
final class OptOuts
{
    /**
     * @return list<string> The user IDs of everyone who opted out.
     */
    public function all(): array
    {
        return $this->table()->pluck('user_id')->all();
    }

    /**
     * @return bool Whether this changed anything: false when they had already opted out.
     */
    public function add(string $userId): bool
    {
        return $this->table()->insertOrIgnore(['user_id' => $userId, 'created_at' => gmdate('Y-m-d H:i:s')]) === 1;
    }

    /**
     * @return bool Whether this changed anything: false when they hadn't opted out.
     */
    public function remove(string $userId): bool
    {
        return $this->table()->where('user_id', $userId)->delete() === 1;
    }

    /**
     * The opt-outs table, created the first time it is needed.
     */
    private function table(): Builder
    {
        $connection = DB::connection(Usage::CONNECTION);

        if (! $connection->getSchemaBuilder()->hasTable('opt_outs')) {
            $connection->getSchemaBuilder()->create('opt_outs', function (Blueprint $table) {
                $table->string('user_id')->primary();
                $table->dateTime('created_at');
            });
        }

        return $connection->table('opt_outs');
    }
}
