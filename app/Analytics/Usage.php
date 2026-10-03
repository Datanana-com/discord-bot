<?php

declare(strict_types=1);

namespace App\Analytics;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Usage statistics, shown by /stats: one row per thing that happened in a call, in the "stats"
 * database from configs/database.php. Nothing anyone said is stored, only what happened and when.
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

    /** Something said could not be transcribed or answered, or the answer could not be spoken. */
    public const string FAILED = 'failed';

    private bool $tableExists = false;

    public function __construct(private readonly LoggerInterface $log)
    {
    }

    /**
     * @param string $type One of the constants above.
     * @param array{channel?: string, user?: string, session?: string, duration_ms?: int} $details
     */
    public function record(string $type, string $guildId, array $details = []): void
    {
        try {
            $this->events()->insert([
                'type' => $type,
                'guild_id' => $guildId,
                'channel_id' => $details['channel'] ?? null,
                'user_id' => $details['user'] ?? null,
                'session_id' => $details['session'] ?? null,
                'duration_ms' => $details['duration_ms'] ?? null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            $this->log->warning('Could not save usage statistics: ' . $e->getMessage(), ['type' => $type, 'guild' => $guildId]);
        }
    }

    /**
     * A server's usage so far, or null when the statistics can't be read.
     *
     * @return array{since: ?string, calls: int, call_ms: int, speakers: int, utterances: int, speech_ms: int, answers: int, answer_ms: ?int, failures: int}|null
     *         since is the first event's time, in UTC; answer_ms is the average time to answer.
     */
    public function summary(string $guildId): ?array
    {
        try {
            $row = $this->events()
                ->where('guild_id', $guildId)
                ->selectRaw(
                    'MIN(created_at) AS since,
                    SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS calls,
                    SUM(CASE WHEN type = ? THEN duration_ms ELSE 0 END) AS call_ms,
                    COUNT(DISTINCT CASE WHEN type = ? THEN user_id END) AS speakers,
                    SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS utterances,
                    SUM(CASE WHEN type = ? THEN duration_ms ELSE 0 END) AS speech_ms,
                    SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS answers,
                    AVG(CASE WHEN type = ? THEN duration_ms END) AS answer_ms,
                    SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS failures',
                    [self::CALL_STARTED, self::CALL_ENDED, self::UTTERANCE, self::UTTERANCE, self::UTTERANCE, self::ANSWERED, self::ANSWERED, self::FAILED],
                )
                ->first();
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
            'failures' => (int) $row->failures,
        ];
    }

    /**
     * The events table, created the first time it is needed.
     */
    private function events(): Builder
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

        return $connection->table('events');
    }
}
