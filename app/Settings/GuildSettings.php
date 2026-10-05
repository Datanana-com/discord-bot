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
 * What each server changed with /settings: its wake word, whisper's language, the Piper voice and
 * the Claude model. One row per server, in the "stats" database from configs/database.php.
 *
 * A server's settings are an array of those four, by column name. One it didn't change is null,
 * and the .env default is used for it.
 *
 * Settings never get in the way of the bot: when they can't be read, that is logged and a call
 * starts with the .env defaults.
 */
final class GuildSettings
{
    /** The settings of a server that changed nothing. */
    public const array DEFAULTS = ['wake_word' => null, 'language' => null, 'voice' => null, 'model' => null];

    private bool $tableExists = false;

    public function __construct(private readonly LoggerInterface $log)
    {
    }

    /**
     * A server's settings, or null when the settings can't be read.
     *
     * @return array{wake_word: ?string, language: ?string, voice: ?string, model: ?string}|null
     */
    public function find(string $guildId): ?array
    {
        try {
            $row = $this->table()->where('guild_id', $guildId)->first(array_keys(self::DEFAULTS));
        } catch (Throwable $e) {
            $this->log->warning('Could not read the server settings: ' . $e->getMessage(), ['guild' => $guildId]);

            return null;
        }

        return $row === null ? self::DEFAULTS : (array) $row;
    }

    /**
     * The settings a call in the server starts with: the .env defaults when they can't be read.
     *
     * @return array{wake_word: ?string, language: ?string, voice: ?string, model: ?string}
     */
    public function for(string $guildId): array
    {
        return $this->find($guildId) ?? self::DEFAULTS;
    }

    /**
     * Replaces a server's settings.
     *
     * @param array{wake_word: ?string, language: ?string, voice: ?string, model: ?string} $settings
     * @param string $userId Who changed them.
     * @return bool Whether they were saved.
     */
    public function save(string $guildId, array $settings, string $userId): bool
    {
        try {
            $this->table()->updateOrInsert(
                ['guild_id' => $guildId],
                [...$settings, 'updated_by' => $userId, 'updated_at' => gmdate('Y-m-d H:i:s')],
            );
        } catch (Throwable $e) {
            $this->log->warning('Could not save the server settings: ' . $e->getMessage(), ['guild' => $guildId]);

            return false;
        }

        return true;
    }

    /**
     * The guild_settings table, created the first time it is needed.
     */
    private function table(): Builder
    {
        $connection = DB::connection(Usage::CONNECTION);

        if (! $this->tableExists && ! $connection->getSchemaBuilder()->hasTable('guild_settings')) {
            $connection->getSchemaBuilder()->create('guild_settings', function (Blueprint $table) {
                $table->string('guild_id')->primary();
                $table->string('wake_word')->nullable();
                $table->string('language')->nullable();
                $table->string('voice')->nullable();
                $table->string('model')->nullable();
                $table->string('updated_by');
                $table->dateTime('updated_at');
            });
        }

        $this->tableExists = true;

        return $connection->table('guild_settings');
    }
}
