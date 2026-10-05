<?php

declare(strict_types=1);

namespace App\Assistant;

use RuntimeException;

/**
 * Thrown for a voice message longer than {@see VoiceMessage::MAX_SECONDS}. Its message is
 * written for the person who sent it.
 */
final class VoiceMessageTooLongException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(sprintf(
            "That voice message is longer than %d minutes, and transcribing it would take too long. Could you send a shorter one, or write it down?",
            VoiceMessage::MAX_SECONDS / 60,
        ));
    }
}
