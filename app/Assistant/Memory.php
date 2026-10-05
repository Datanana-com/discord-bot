<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Voice\SentenceSplitter;
use InvalidArgumentException;
use RuntimeException;

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
        $directory = rtrim((string) env('MEMORY_PATH', 'memories'), '/');

        // An empty MEMORY_PATH is the default, rather than the root of the disk.
        return new self($directory === '' ? 'memories' : $directory);
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
     * Cuts a memory that is too long after its last line or sentence that fits, or when there is
     * none, after its last word, so as much as possible is kept and it still reads well.
     */
    private static function cut(string $memory): string
    {
        if (mb_strlen($memory) <= self::LIMIT) {
            return $memory;
        }

        // One character more shows whether a line, sentence or word ends exactly at the limit.
        $window = mb_substr($memory, 0, self::LIMIT + 1);

        foreach (['/^.+(?:(?=\n)|' . SentenceSplitter::END . ')/su', '/^.+(?=\s)/su'] as $ending) {
            if (preg_match($ending, $window, $match) === 1) {
                return rtrim($match[0]);
            }
        }

        return mb_substr($memory, 0, self::LIMIT);
    }

    /**
     * Replaces what is remembered about the person.
     *
     * @return string The memory as it was saved: cut to the limit when it was longer.
     *
     * @throws RuntimeException When it can't be saved. The memory is then as it was.
     */
    public function save(string $userId, string $memory): string
    {
        $path = $this->path($userId);
        $memory = self::cut(trim($memory));

        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0700, true);
        }

        // tempnam() creates a file only the bot's user can read (mode 0600), and renaming it
        // into place never leaves half a memory behind. When it can't create one in the folder,
        // it uses the system's temporary folder instead, where the memory has no place.
        $temporary = @tempnam($this->directory, 'memory-');
        $saved = $temporary !== false
            && dirname($temporary) === realpath($this->directory)
            && file_put_contents($temporary, $memory . PHP_EOL) === strlen($memory . PHP_EOL)
            && rename($temporary, $path);

        if (! $saved) {
            if ($temporary !== false && is_file($temporary)) {
                unlink($temporary);
            }

            throw new RuntimeException("The memory could not be saved in {$this->directory}.");
        }

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
