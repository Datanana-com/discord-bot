<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Voice\SentenceSplitter;
use InvalidArgumentException;
use RuntimeException;

/**
 * What the bot remembers about each person, and about each group of people it has calls with:
 * a markdown file for each, written by Claude.
 *
 * A person's memory is at `<directory>/<user ID>.md`. A group's is at `<directory>/groups/<the
 * people's user IDs, from the lowest, joined with ->.md`, and belongs to exactly those people.
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
     * The people a memory belongs to, as the memory sees them: each once, from the lowest user ID.
     *
     * @param string|list<string> $people A user ID, or the user IDs of a group.
     * @return list<string>
     *
     * @throws InvalidArgumentException When there is nobody, or something isn't a Discord user ID.
     */
    public static function people(string|array $people): array
    {
        $people = array_values(array_unique((array) $people));

        // The IDs become a file name, so they can only be what Discord's IDs are: digits.
        foreach ($people as $userId) {
            if (! ctype_digit($userId)) {
                throw new InvalidArgumentException("Not a Discord user ID: {$userId}");
            }
        }

        if ($people === []) {
            throw new InvalidArgumentException('A memory belongs to somebody.');
        }

        // IDs grow with time, so a shorter ID is a lower one.
        usort($people, fn (string $a, string $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));

        return $people;
    }

    /**
     * @param string|list<string> $people A user ID, or the user IDs of a group.
     * @return string What is remembered about them, or an empty string when nothing is.
     */
    public function read(string|array $people): string
    {
        $path = $this->path($people);

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
     * Replaces what is remembered about the person, or the group.
     *
     * @param string|list<string> $people A user ID, or the user IDs of a group.
     * @return string The memory as it was saved: cut to the limit when it was longer.
     *
     * @throws RuntimeException When it can't be saved. The memory is then as it was.
     */
    public function save(string|array $people, string $memory): string
    {
        $path = $this->path($people);
        $folder = dirname($path);
        $memory = self::cut(trim($memory));

        if (! is_dir($folder)) {
            @mkdir($folder, 0700, true);
        }

        // tempnam() creates a file only the bot's user can read (mode 0600), and renaming it
        // into place never leaves half a memory behind. When it can't create one in the folder,
        // it uses the system's temporary folder instead, where the memory has no place.
        $temporary = @tempnam($folder, 'memory-');
        $saved = $temporary !== false
            && dirname($temporary) === realpath($folder)
            && file_put_contents($temporary, $memory . PHP_EOL) === strlen($memory . PHP_EOL)
            && rename($temporary, $path);

        if (! $saved) {
            if ($temporary !== false && is_file($temporary)) {
                unlink($temporary);
            }

            throw new RuntimeException("The memory could not be saved in {$folder}.");
        }

        return $memory;
    }

    /**
     * Deletes what is remembered about the person, or the group.
     *
     * @param string|list<string> $people A user ID, or the user IDs of a group.
     * @return bool Whether there was anything to forget.
     */
    public function forget(string|array $people): bool
    {
        $path = $this->path($people);

        return is_file($path) && unlink($path);
    }

    /**
     * The groups a person has a memory with.
     *
     * @return list<list<string>> The user IDs of each group, the person's among them.
     */
    public function groups(string $userId): array
    {
        $groups = [];

        foreach (is_dir("{$this->directory}/groups") ? scandir("{$this->directory}/groups") : [] as $file) {
            // Only what the bot saved: a group's memory is named after exactly its people.
            if (preg_match('/^(\d+(?:-\d+)+)\.md$/', $file, $match) === 1) {
                $people = explode('-', $match[1]);

                if (in_array($userId, $people, true) && $match[1] === implode('-', self::people($people))) {
                    $groups[] = $people;
                }
            }
        }

        // The smallest groups first, then by who is in them.
        usort($groups, fn (array $a, array $b) => count($a) <=> count($b) ?: strcmp(implode('-', $a), implode('-', $b)));

        return $groups;
    }

    /**
     * @param string|list<string> $people
     */
    private function path(string|array $people): string
    {
        $people = self::people($people);

        return count($people) === 1
            ? "{$this->directory}/{$people[0]}.md"
            : "{$this->directory}/groups/" . implode('-', $people) . '.md';
    }
}
