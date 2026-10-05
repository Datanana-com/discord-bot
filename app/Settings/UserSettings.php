<?php

declare(strict_types=1);

namespace App\Settings;

use App\Analytics\Usage;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What each person chose with /privacy. One row per person, in the "stats" database from
 * configs/database.php, created the first time it is needed. Someone with no row has the defaults.
 *
 * Only what keeps something private is read for a decision, so unlike the server settings, nothing
 * here falls back to the default when it can't be read: find() returns null, and the caller keeps
 * what is private private. That is logged, as the one place to see why.
 */
final class UserSettings
{
    /** Personal memory is used in a call when its owner asks, as in direct messages. */
    public const string WHEN_ASKED = 'when_asked';

    /** Personal memory is only used in a call with other people once its owner has used /share in it. */
    public const string AFTER_SHARE = 'after_share';

    /** Every value personal_memory_in_calls can have. */
    public const array PERSONAL_MEMORY_IN_CALLS = [self::WHEN_ASKED, self::AFTER_SHARE];

    /** The settings of a person who chose nothing. */
    public const array DEFAULTS = ['personal_memory_in_calls' => self::WHEN_ASKED];

    private bool $tableExists = false;

    public function __construct(private readonly LoggerInterface $log)
    {
    }

    /**
     * A person's settings, or null when they can't be read: the database can't be used, or what is in it isn't a choice.
     *
     * @return array{personal_memory_in_calls: string}|null
     */
    public function find(string $userId): ?array
    {
        try {
            $row = $this->table()->where('user_id', $userId)->first(array_keys(self::DEFAULTS));
        } catch (Throwable $e) {
            $this->log->warning('Could not read the user settings: ' . $e->getMessage(), ['user' => $userId]);

            return null;
        }

        if ($row !== null && ! in_array($row->personal_memory_in_calls, self::PERSONAL_MEMORY_IN_CALLS, true)) {
            $this->log->warning('The user settings hold a value that is not a choice.', ['user' => $userId]);

            return null;
        }

        return $row === null ? self::DEFAULTS : (array) $row;
    }

    /**
     * Replaces a person's settings.
     *
     * @param array{personal_memory_in_calls: string} $settings
     * @return bool Whether they were saved.
     */
    public function save(string $userId, array $settings): bool
    {
        try {
            $this->table()->updateOrInsert(
                ['user_id' => $userId],
                [...$settings, 'updated_at' => gmdate('Y-m-d H:i:s')],
            );
        } catch (Throwable $e) {
            $this->log->warning('Could not save the user settings: ' . $e->getMessage(), ['user' => $userId]);

            return false;
        }

        return true;
    }

    /**
     * The user_settings table, created the first time it is needed.
     */
    private function table(): Builder
    {
        $connection = DB::connection(Usage::CONNECTION);

        if (! $this->tableExists && ! $connection->getSchemaBuilder()->hasTable('user_settings')) {
            $connection->getSchemaBuilder()->create('user_settings', function (Blueprint $table) {
                $table->string('user_id')->primary();
                $table->string('personal_memory_in_calls');
                $table->dateTime('updated_at');
            });
        }

        $this->tableExists = true;

        return $connection->table('user_settings');
    }
}
