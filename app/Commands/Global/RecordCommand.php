<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\CommandAbstract;
use App\Settings\GuildSettings;
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
        // Read once, so the call starts with the settings that are checked and announced here.
        $settings = (new GuildSettings($this->log))->for((string) $interaction->guild_id);

        $problem = match (true) {
            $voiceChannel === null => 'Join a voice channel first.',
            VoiceSession::forGuild((string) $interaction->guild_id) !== null => 'I am already recording in this server. Use /stop first.',
            $this->discord->voice === null => 'Voice is not available: libdave or ext-ffi could not be loaded. Check the bot logs.',
            default => VoiceSession::missingSetup($settings),
        };

        if ($problem !== null) {
            $this->log->info("/record refused: {$problem}", ['guild' => $interaction->guild_id]);
            $interaction->respondWithMessage(MessageBuilder::new()->setContent($problem), ephemeral: true);

            return;
        }

        // Joining can take longer than the 3 seconds Discord waits for a response.
        $interaction->acknowledgeWithResponse()
            ->then(fn () => $this->discord->joinVoiceChannel($voiceChannel, mute: false, deaf: false))
            ->then(
                function (VoiceClient $vc) use ($interaction, $voiceChannel, $settings) {
                    $session = VoiceSession::start($vc, $interaction->channel ?? $voiceChannel, $this->discord, $settings);

                    return $interaction->updateOriginalResponse(MessageBuilder::new()->setContent(
                        "🔴 Recording <#{$voiceChannel->id}>. "
                        . ($session->wakeWord === '' ? 'I answer everything that is said.' : "Say \"{$session->wakeWord}\" to talk to me.")
                        . ' Use /stop to end the recording.'
                    ));
                },
                function (Throwable $e) use ($interaction, $voiceChannel) {
                    $this->log->error('Could not join the voice channel: ' . $e->getMessage(), ['guild' => $interaction->guild_id, 'channel' => $voiceChannel->id]);

                    return $interaction->updateOriginalResponse(
                        MessageBuilder::new()->setContent('Could not join the voice channel: ' . $e->getMessage())
                    );
                },
            );
    }
}
