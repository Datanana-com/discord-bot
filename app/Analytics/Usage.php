<?php

declare(strict_types=1);

namespace App\Analytics;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Usage statistics, shown by /stats: one row per thing that happened in a call, in the "stats"
 * database from configs/database.php. Nothing anyone said is stored, only what happened and when.
 *
 * A row is held in memory when it happens and written later, with the others, in one transaction: a write to
 * SQLite blocks the event loop for a few milliseconds, and for tens of them now and then, which nobody should
 * wait for while an answer is on its way or being spoken. {@see flush()} is called when nobody does. The rows
 * are held by the class, not by an instance, because /stats and each call make their own: a bot that crashes
 * loses the rows it still held, and the time of each is the time it happened, not the time it was written.
 *
 * Statistics never get in the way of the bot: when they can't be saved or read, that is logged
 * and the bot carries on.
 */
final class Usage
{
    public const string CONNECTION = 'stats';

    /** A call was recorded; the event of its end has the call's length. */
    public const string CALL_STARTED = 'call_started';

    public const string CALL_ENDED = 'call_ended';

    /** Someone said something, of the given length. */
    public const string UTTERANCE = 'utterance';

    /** Claude answered; its duration is from the end of the question to the answer being posted. */
    public const string ANSWERED = 'answered';

    /** Something Claude handed off was looked up, and what was found is posted. */
    public const string LOOKED_UP = 'looked_up';

    /** Something said could not be transcribed or answered, or the answer could not be spoken. */
    public const string FAILED = 'failed';

    /** Rows to one INSERT: SQLite allows 999 values in a statement at the least, and a row has 7. */
    private const int INSERT_ROWS = 100;

    /**
     * @var list<array{type: string, guild_id: string, channel_id: ?string, user_id: ?string, session_id: ?string, duration_ms: ?int, created_at: string}>
     */
    private static array $held = [];

    private bool $tableExists = false;

    public function __construct(private readonly LoggerInterface $log)
    {
    }

    /**
     * Keeps something that happened, to be written by {@see flush()}. Nothing is written here.
     *
     * @param string $type One of the constants above.
     * @param array{channel?: string, user?: string, session?: string, duration_ms?: int} $details
     */
    public function record(string $type, string $guildId, array $details = []): void
    {
        self::$held[] = [
            'type' => $type,
            'guild_id' => $guildId,
            'channel_id' => $details['channel'] ?? null,
            'user_id' => $details['user'] ?? null,
            'session_id' => $details['session'] ?? null,
            'duration_ms' => $details['duration_ms'] ?? null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Writes what is held, in the order it happened, in one transaction. Rows that can't be written are logged
     * once and dropped, as the one row that couldn't be was before: statistics are not worth holding on to.
     */
    public function flush(): void
    {
        if (self::$held === []) {
            return;
        }

        // Taken first: whatever is recorded while this writes is for the next time.
        $rows = self::$held;
        self::$held = [];

        try {
            $connection = $this->connection();
            $connection->transaction(function () use ($connection, $rows) {
                foreach (array_chunk($rows, self::INSERT_ROWS) as $chunk) {
                    $connection->table('events')->insert($chunk);
                }
            });
        } catch (Throwable $e) {
            $this->log->warning('Could not save usage statistics: ' . $e->getMessage(), ['rows' => count($rows)]);
        }
    }

    /**
     * Forgets what is held, without writing it. For tests, which each have a database of their own.
     */
    public static function reset(): void
    {
        self::$held = [];
    }

    /**
     * A server's usage so far, or null when the statistics can't be read. What is held and not yet written
     * counts too: reading it writes nothing, as it may be asked for while an answer is spoken.
     *
     * @return array{since: ?string, calls: int, call_ms: int, speakers: int, utterances: int, speech_ms: int, answers: int, answer_ms: ?int, lookups: int, failures: int}|null
     *         since is the first event's time, in UTC; answer_ms is the average time to answer.
     */
    public function summary(string $guildId): ?array
    {
        $seen = 'SELECT type, user_id, duration_ms, created_at FROM events WHERE guild_id = ?';
        $bindings = [$guildId];
        $held = array_values(array_filter(self::$held, fn (array $row) => $row['guild_id'] === $guildId));

        if ($held !== []) {
            $seen .= ' UNION ALL VALUES ' . implode(', ', array_fill(0, count($held), '(?, ?, ?, ?)'));

            foreach ($held as $row) {
                array_push($bindings, $row['type'], $row['user_id'], $row['duration_ms'], $row['created_at']);
            }
        }

        try {
            $row = $this->connection()->selectOne(
                "WITH seen (type, user_id, duration_ms, created_at) AS ({$seen})
                SELECT MIN(created_at) AS since,
                    SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS calls,
                    SUM(CASE WHEN type = ? THEN duration_ms ELSE 0 END) AS call_ms,
                    COUNT(DISTINCT CASE WHEN type = ? THEN user_id END) AS speakers,
                    SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS utterances,
                    SUM(CASE WHEN type = ? THEN duration_ms ELSE 0 END) AS speech_ms,
                    SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS answers,
                    AVG(CASE WHEN type = ? THEN duration_ms END) AS answer_ms,
                    SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS lookups,
                    SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS failures
                FROM seen",
                [...$bindings, self::CALL_STARTED, self::CALL_ENDED, self::UTTERANCE, self::UTTERANCE, self::UTTERANCE, self::ANSWERED, self::ANSWERED, self::LOOKED_UP, self::FAILED],
            );
        } catch (Throwable $e) {
            $this->log->warning('Could not read usage statistics: ' . $e->getMessage(), ['guild' => $guildId]);

            return null;
        }

        return [
            'since' => $row->since,
            'calls' => (int) $row->calls,
            'call_ms' => (int) $row->call_ms,
            'speakers' => (int) $row->speakers,
            'utterances' => (int) $row->utterances,
            'speech_ms' => (int) $row->speech_ms,
            'answers' => (int) $row->answers,
            'answer_ms' => $row->answer_ms === null ? null : (int) round((float) $row->answer_ms),
            'lookups' => (int) $row->lookups,
            'failures' => (int) $row->failures,
        ];
    }

    /**
     * The statistics database, with the events table created the first time it is needed.
     */
    private function connection(): ConnectionInterface
    {
        $connection = DB::connection(self::CONNECTION);

        if (! $this->tableExists && ! $connection->getSchemaBuilder()->hasTable('events')) {
            $connection->getSchemaBuilder()->create('events', function (Blueprint $table) {
                $table->id();
                $table->string('type');
                $table->string('guild_id');
                $table->string('channel_id')->nullable();
                $table->string('user_id')->nullable();
                $table->string('session_id')->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
                $table->dateTime('created_at');
                $table->index(['guild_id', 'type']);
            });
        }

        $this->tableExists = true;

        return $connection;
    }
}
