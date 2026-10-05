<?php

declare(strict_types=1);

namespace Tests;

/**
 * Builds what tests/Fixtures/fake-claude prints: the lines of `claude -p --output-format stream-json`.
 */
trait FakesClaudeOutput
{
    /**
     * An answer Claude wrote in these pieces: an event for each piece, then the result with the whole answer.
     */
    protected static function claudeStream(string ...$pieces): string
    {
        return implode("\n", [...array_map(self::claudeText(...), $pieces), self::claudeResult(implode('', $pieces))]);
    }

    /**
     * The event for a piece of the answer, sent while Claude is writing it.
     */
    protected static function claudeText(string $text): string
    {
        return json_encode([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]],
        ]);
    }

    /**
     * The last event: the whole answer, or why there is none.
     */
    protected static function claudeResult(string $result, bool $isError = false): string
    {
        return json_encode(['type' => 'result', 'subtype' => 'success', 'is_error' => $isError, 'result' => $result]);
    }
}
