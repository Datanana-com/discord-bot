<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\CommandAbstract;
use App\Commands\RecordsCalls;
use App\Settings\GuildSettings;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use Throwable;

final class RecordCommand extends CommandAbstract
{
    use RecordsCalls;

    public string $description = 'Records your voice channel and lets everyone in it talk to Claude.';

    public function handle(Interaction $interaction): void
    {
        $voiceChannel = $interaction->member?->getVoiceChannel();
        // Read once, so the call starts with the settings that are checked and announced here.
        $settings = (new GuildSettings($this->log))->for((string) $interaction->guild_id);

        $problem = $voiceChannel === null ? 'Join a voice channel first.' : $this->recordingProblem($interaction, $settings);

        if ($problem !== null) {
            $this->refuse($interaction, '/record', $problem);

            return;
        }

        // Joining can take longer than the 3 seconds Discord waits for a response.
        $interaction->acknowledgeWithResponse()
            ->then(fn () => $this->record($interaction, $voiceChannel, $interaction->channel ?? $voiceChannel, $settings))
            ->then(
                fn (VoiceSession $session) => $interaction->updateOriginalResponse(MessageBuilder::new()->setContent(
                    "🔴 Recording <#{$voiceChannel->id}>. "
                    . $this->howToTalk($session)
                    . ' Use /stop to end the recording, or /optout if you don\'t want to be recorded.'
                )),
                fn (Throwable $e) => $interaction->updateOriginalResponse(MessageBuilder::new()->setContent($e->getMessage())),
            );
    }
}
