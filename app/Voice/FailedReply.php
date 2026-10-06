<?php

declare(strict_types=1);

namespace App\Voice;

use RuntimeException;
use Throwable;

/**
 * Something said in a call couldn't be transcribed or answered, or the answer couldn't be spoken.
 *
 * It says which step failed, for the call to be told what fits: what can't be spoken, for one, can't be
 * said sorry for out loud. Its message is that of what the step failed with.
 */
final class FailedReply extends RuntimeException
{
    public const string WHISPER = 'whisper';

    public const string CLAUDE = 'claude';

    /** Piper could not synthesize a sentence, or the voice client could not play it. */
    public const string SPEECH = 'speech';

    /** Not one of the steps: something the bot doesn't expect to fail. */
    public const string OTHER = 'other';

    /**
     * @param self::WHISPER|self::CLAUDE|self::SPEECH $step
     */
    public function __construct(public readonly string $step, Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }

    /**
     * What failed as that step, unless it is already known which step failed: a sentence waits for the
     * ones before it, and fails with them.
     *
     * @param self::WHISPER|self::CLAUDE|self::SPEECH $step
     */
    public static function of(string $step, Throwable $e): self
    {
        return $e instanceof self ? $e : new self($step, $e);
    }

    /**
     * @return self::WHISPER|self::CLAUDE|self::SPEECH|self::OTHER
     */
    public static function stepOf(Throwable $e): string
    {
        return $e instanceof self ? $e->step : self::OTHER;
    }
}
