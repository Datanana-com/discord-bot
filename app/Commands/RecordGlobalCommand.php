<?php

namespace App\Commands;

use App\CommandAbstract;
use Discord\Parts\Interactions\Interaction;

final class RecordGlobalCommand extends CommandAbstract
{
    public string $description = 'Starts recording the current voice channel.';

    public function handle(Interaction $interaction): void
    {
        $this->log->info('Hello, World!');

        $voiceChannelToJoin = $interaction->member->getVoiceChannel();
        $this->discord->joinVoiceChannel($voiceChannelToJoin, mute: true, deaf: false);
    }
}
