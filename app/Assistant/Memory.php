<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Voice\VoiceSession;
use InvalidArgumentException;

/**
 * What the bot remembers about each person: a markdown file per person, written by Claude.
 *
 * The files stay on the bot's machine, where only the bot's user can read them.
 */
final readonly class Memory
{
    /** Characters a memory can hold, so it fits in every prompt. */
    public const int LIMIT = 4000;

    public function __construct(
        public string $directory,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(rtrim(env('MEMORY_PATH', 'memories'), '/'));
    }

    /**
     * @return string What is remembered about the person, or an empty string when nothing is.
     */
    public function read(string $userId): string
    {
        $path = $this->path($userId);

        return is_file($path) ? trim(file_get_contents($path)) : '';
    }

    /**
     * Replaces what is remembered about the person.
     *
     * @return string The memory as it was saved: cut to the limit when it was longer.
     */
    public function save(string $userId, string $memory): string
    {
        $path = $this->path($userId);
        // Cut after a line, a sentence or a word, so what is kept still reads well.
        $memory = VoiceSession::split(trim($memory), self::LIMIT)[0];

        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }

        // tempnam() creates a file only the bot's user can read (mode 0600), and renaming it
        // into place never leaves half a memory behind.
        $temporary = tempnam($this->directory, 'memory-');
        file_put_contents($temporary, $memory . PHP_EOL);
        rename($temporary, $path);

        return $memory;
    }

    /**
     * Deletes what is remembered about the person.
     *
     * @return bool Whether there was anything to forget.
     */
    public function forget(string $userId): bool
    {
        $path = $this->path($userId);

        return is_file($path) && unlink($path);
    }

    private function path(string $userId): string
    {
        // The ID becomes a file name, so it can only be what Discord's IDs are: digits.
        if (! ctype_digit($userId)) {
            throw new InvalidArgumentException("Not a Discord user ID: {$userId}");
        }

        return "{$this->directory}/{$userId}.md";
    }
}
