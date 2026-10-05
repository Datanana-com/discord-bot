<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\Assistant\DirectChat;
use App\Assistant\Memory;
use App\CommandAbstract;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;

final class ForgetCommand extends CommandAbstract
{
    public string $description = 'Deletes what the bot remembers about you.';

    public function handle(Interaction $interaction): void
    {
        $userId = (string) $interaction->user->id;

        // What they said since the memory was last updated would otherwise be remembered later.
        DirectChat::forget($userId);

        $interaction->respondWithMessage(
            MessageBuilder::new()->setContent(
                Memory::fromEnv()->forget($userId)
                    ? 'Done: I forgot what I remembered about you.'
                    : "I don't remember anything about you, so there is nothing to forget."
            ),
            ephemeral: true,
        );
    }
}
