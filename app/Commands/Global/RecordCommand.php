<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\CommandAbstract;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use Discord\Voice\VoiceClient;
use Throwable;

final class RecordCommand extends CommandAbstract
{
    public string $description = 'Records your voice channel and lets everyone in it talk to Claude.';

    public function handle(Interaction $interaction): void
    {
        $voiceChannel = $interaction->member?->getVoiceChannel();

        $problem = match (true) {
            $voiceChannel === null => 'Join a voice channel first.',
            VoiceSession::forGuild((string) $interaction->guild_id) !== null => 'I am already recording in this server. Use /stop first.',
            $this->discord->voice === null => 'Voice is not available: libdave or ext-ffi could not be loaded. Check the bot logs.',
            default => VoiceSession::missingSetup(),
        };

        if ($problem !== null) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent($problem), ephemeral: true);

            return;
        }

        // Joining can take longer than the 3 seconds Discord waits for a response.
        $interaction->acknowledgeWithResponse()
            ->then(fn () => $this->discord->joinVoiceChannel($voiceChannel, mute: false, deaf: false))
            ->then(
                function (VoiceClient $vc) use ($interaction, $voiceChannel) {
                    VoiceSession::start($vc, $interaction->channel ?? $voiceChannel, $this->discord);
                    $wakeWord = trim(env('VOICE_WAKE_WORD', 'claude'));

                    return $interaction->updateOriginalResponse(MessageBuilder::new()->setContent(
                        "🔴 Recording <#{$voiceChannel->id}>. "
                        . ($wakeWord === '' ? 'I answer everything that is said.' : "Say \"{$wakeWord}\" to talk to me.")
                        . ' Use /stop to end the recording.'
                    ));
                },
                function (Throwable $e) use ($interaction) {
                    $this->log->error('Could not join the voice channel: ' . $e->getMessage());

                    return $interaction->updateOriginalResponse(
                        MessageBuilder::new()->setContent('Could not join the voice channel: ' . $e->getMessage())
                    );
                },
            );
    }
}
