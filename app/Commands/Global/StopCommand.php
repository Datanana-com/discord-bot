<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\CommandAbstract;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use React\Promise\PromiseInterface;

final class StopCommand extends CommandAbstract
{
    public string $description = 'Stops recording and leaves the voice channel.';

    public function handle(Interaction $interaction): ?PromiseInterface
    {
        $session = VoiceSession::forGuild((string) $interaction->guild_id);

        if ($session === null) {
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent('I am not recording in this server.'), ephemeral: true);
        }

        $session->stop();

        return $interaction->respondWithMessage(
            MessageBuilder::new()->setContent("⏹️ Stopped recording. Saved to `{$session->directory}`.")
        );
    }
}
