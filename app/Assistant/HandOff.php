<?php

declare(strict_types=1);

namespace App\Assistant;

use Closure;

/**
 * Finds the line Claude ends an answer with to hand a question off: "LOOK UP:" followed by the task.
 *
 * Only the answer's last line counts, and only when it starts with that. Such a line anywhere else is
 * part of the answer like any other, and so is what someone said: this only ever gets what Claude wrote.
 *
 * An answer is spoken while it is written, so the text is passed on piece by piece, except for the
 * start of a line that is that line so far, or may still become it: it can't be spoken until it is
 * known not to be the hand-off.
 */
final class HandOff
{
    private const string MARKER = 'LOOK UP:';

    /** What arrived from the start of a line that is the hand-off line so far, or may still become it. */
    private string $held = '';

    /** Whether the text passed on ends in the middle of a line: what arrives next then doesn't start one. */
    private bool $midLine = false;

    /**
     * @param Closure(string $text): void $onText Called with each piece of the answer that isn't the hand-off line.
     */
    public function __construct(private readonly Closure $onText)
    {
    }

    /**
     * Splits a whole answer into what it says and what it hands off.
     *
     * @return array{string, string|null} The answer without its hand-off line, and the task, or null when it hands nothing off.
     */
    public static function split(string $answer): array
    {
        $said = '';
        $handOff = new self(function (string $text) use (&$said) {
            $said .= $text;
        });
        $handOff->push($answer);
        $task = $handOff->flush();

        return [trim($said), $task];
    }

    /**
     * Adds the next piece of the answer.
     */
    public function push(string $text): void
    {
        $this->held .= $text;

        // What is held always starts a line: what was passed on before it ended with one.
        $this->pass(self::start($this->held, ! $this->midLine) ?? strlen($this->held));
    }

    /**
     * Ends the answer: what is still held is its hand-off line, or the end of what it says.
     *
     * @return string|null The task it hands off, or null when it hands nothing off.
     */
    public function flush(): ?string
    {
        if (! str_starts_with($this->held, self::MARKER)) {
            $this->pass(strlen($this->held));

            return null;
        }

        $task = trim(substr($this->held, strlen(self::MARKER)));
        $this->held = '';

        // A line with no task hands nothing off, and is still not something to say.
        return $task === '' ? null : $task;
    }

    /**
     * Where the hand-off line starts in a text: its last line that isn't blank, when that starts with the
     * marker, or its last line, while that is the start of the marker and may still become it.
     *
     * @param bool $startsLine Whether the text starts a line. Its first line is no candidate otherwise.
     */
    private static function start(string $text, bool $startsLine): ?int
    {
        $written = rtrim($text);
        $line = self::lastLine($written);

        if (($line > 0 || $startsLine) && str_starts_with(substr($written, $line), self::MARKER)) {
            return $line;
        }

        $line = self::lastLine($text);
        $start = substr($text, $line);

        // An empty last line is the start of the marker too: nothing of it is there to hold yet.
        return ($line > 0 || $startsLine) && str_starts_with(self::MARKER, $start) ? $line : null;
    }

    /**
     * Where the last line of a text starts.
     */
    private static function lastLine(string $text): int
    {
        $newline = strrpos($text, "\n");

        return $newline === false ? 0 : $newline + 1;
    }

    /**
     * Passes on the start of what is held, and keeps the rest.
     */
    private function pass(int $length): void
    {
        $text = substr($this->held, 0, $length);
        $this->held = substr($this->held, $length);

        if ($text !== '') {
            $this->midLine = ! str_ends_with($text, "\n");
            ($this->onText)($text);
        }
    }
}
