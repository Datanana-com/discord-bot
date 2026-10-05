<?php

declare(strict_types=1);

namespace App\Voice;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use Throwable;

/**
 * Deletes the recordings of old calls, so they don't fill the disk and aren't kept
 * longer than anyone needs them.
 *
 * A call's folder is RECORDINGS_PATH/<server id>/<Y-m-d_H-i-s>, as {@see VoiceSession::start()}
 * creates it, and its name says when the call started. Everything else in RECORDINGS_PATH is
 * left alone, and so are the statistics and the logs: neither holds anything anyone said.
 */
final readonly class Retention
{
    /** How a call's folder is named, after when the call started. */
    private const string FOLDER_FORMAT = 'Y-m-d_H-i-s';

    /** Seconds between two checks for old recordings. */
    private const int INTERVAL = 3600;

    /**
     * @param int $days Calls older than this are deleted.
     */
    public function __construct(
        private string $path,
        private int $days,
        private LoggerInterface $log,
    ) {
    }

    /**
     * Returns null when RECORDINGS_RETENTION_DAYS isn't set: every recording is then kept.
     */
    public static function fromEnv(LoggerInterface $log): ?self
    {
        $value = env('RECORDINGS_RETENTION_DAYS', '');

        if ($value === '') {
            return null;
        }

        // env() turns "true" into a boolean, which would otherwise read as one day.
        $days = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        if ($days === false) {
            // Anything else could be a typo, and guessing a number of days from it could delete everything.
            $log->warning('RECORDINGS_RETENTION_DAYS is not a whole number of days, 1 or more: no recordings are deleted.');

            return null;
        }

        return new self(env('RECORDINGS_PATH', 'recordings'), $days, $log);
    }

    /**
     * Deletes old recordings now, then every hour.
     */
    public function start(LoopInterface $loop): void
    {
        $this->run();

        $loop->addPeriodicTimer(self::INTERVAL, $this->run(...));
    }

    /**
     * Deletes the folders of the calls that are older than the number of days, and the
     * server folders this leaves empty.
     *
     * @return int The number of calls deleted.
     */
    public function prune(DateTimeImmutable $now): int
    {
        // Counted in seconds: a number of days too large for a date then keeps everything, as it should.
        $oldest = $now->getTimestamp() - $this->days * 86400;
        $calls = 0;

        foreach ($this->folders($this->path) as $guildId) {
            // Server IDs are numbers. Other folders aren't the bot's.
            if (! ctype_digit($guildId)) {
                continue;
            }

            $guild = "{$this->path}/{$guildId}";
            $inProgress = VoiceSession::forGuild($guildId)?->directory;

            foreach ($this->folders($guild) as $call) {
                $startedAt = DateTimeImmutable::createFromFormat(self::FOLDER_FORMAT, $call);

                // Formatting the date again rejects names like 2026-13-45_00-00-00, which are read as a later date.
                if ($startedAt === false || $startedAt->format(self::FOLDER_FORMAT) !== $call) {
                    continue;
                }

                // A call in progress is still being written to, however long ago it started.
                if ($startedAt->getTimestamp() >= $oldest || ($inProgress !== null && basename($inProgress) === $call)) {
                    continue;
                }

                if ($this->delete("{$guild}/{$call}")) {
                    $calls++;
                } else {
                    $this->log->warning('Could not delete old recordings', ['guild' => $guildId, 'call' => $call]);
                }
            }

            // Only an empty folder can be removed this way.
            @rmdir($guild);
        }

        if ($calls > 0) {
            $this->log->info('Deleted old recordings', ['calls' => $calls, 'days' => $this->days]);
        }

        return $calls;
    }

    private function run(): void
    {
        try {
            $this->prune(new DateTimeImmutable());
        } catch (Throwable $e) {
            // An error that got out of here would stop the bot, and with it every call in progress.
            $this->log->warning('Could not delete old recordings: ' . $e->getMessage());
        }
    }

    /**
     * Deletes a file, or a folder with everything in it.
     *
     * @return bool Whether it is gone.
     */
    private function delete(string $path): bool
    {
        // A link is deleted itself, never what it points to.
        if (is_link($path) || ! is_dir($path)) {
            return @unlink($path);
        }

        foreach ($this->entries($path) as $entry) {
            $this->delete("{$path}/{$entry}");
        }

        // Fails when something in the folder couldn't be deleted.
        return @rmdir($path);
    }

    /**
     * @return list<string> The names of the folders in a folder, without links to folders.
     */
    private function folders(string $path): array
    {
        return array_values(array_filter(
            $this->entries($path),
            fn (string $name) => is_dir("{$path}/{$name}") && ! is_link("{$path}/{$name}"),
        ));
    }

    /**
     * @return list<string> The names of everything in a folder; nothing when it can't be read.
     */
    private function entries(string $path): array
    {
        return array_values(array_diff(@scandir($path) ?: [], ['.', '..']));
    }
}
