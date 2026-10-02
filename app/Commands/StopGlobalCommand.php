<?php

declare(strict_types=1);

namespace App\Commands;

use App\CommandAbstract;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;

final class StopGlobalCommand extends CommandAbstract
{
    public string $description = 'Stops recording and leaves the voice channel.';

    public function handle(Interaction $interaction): void
    {
        $session = VoiceSession::forGuild((string) $interaction->guild_id);

        if ($session === null) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('I am not recording in this server.'), ephemeral: true);

            return;
        }

        $session->stop();

        $interaction->respondWithMessage(
            MessageBuilder::new()->setContent("⏹️ Stopped recording. Saved to `{$session->directory}`.")
        );
    }
}
