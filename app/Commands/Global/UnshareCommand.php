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
        // Not only from inside the call: someone who left it can still take their memory back.
        $stopped = VoiceSession::forGuild((string) $interaction->guild_id)?->unshare((string) $interaction->user->id) ?? false;

        $interaction->respondWithMessage(MessageBuilder::new()->setContent(
            $stopped
                ? 'Stopped sharing your memory with the call. Everyone in the call was told.'
                : 'You are not sharing your memory with a call I am recording.'
        ), ephemeral: true);
    }
}
