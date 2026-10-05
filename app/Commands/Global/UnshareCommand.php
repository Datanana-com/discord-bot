<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\CommandAbstract;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;

final class UnshareCommand extends CommandAbstract
{
    public string $description = 'Takes your personal memory back from the call it was shared with.';

    public function handle(Interaction $interaction): void
    {
        // Not only from inside the call, nor from its server: someone who left it, or who writes in a direct message, can still take their memory back.
        $stopped = VoiceSession::unshareEverywhere((string) $interaction->user->id);

        $interaction->respondWithMessage(MessageBuilder::new()->setContent(
            $stopped
                ? 'Stopped sharing your memory with the call. A notice goes to the call\'s text channel.'
                : 'You are not sharing your memory with a call I am recording.'
        ), ephemeral: true);
    }
}
