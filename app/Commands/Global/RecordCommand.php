<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\CommandAbstract;
use App\Privacy\OptOuts;
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
            default => VoiceSession::missingSetup($settings) ?? $this->optOutsProblem($interaction),
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
                    try {
                        $session = VoiceSession::start($vc, $interaction->channel ?? $voiceChannel, $this->discord, $settings);
                    } catch (Throwable $e) {
                        // The call starts with the list as it is now, which could still be read before joining.
                        $vc->close();

                        return $interaction->updateOriginalResponse(MessageBuilder::new()->setContent($this->optOutsUnreadable($e, $interaction)));
                    }

                    return $interaction->updateOriginalResponse(MessageBuilder::new()->setContent(
                        "🔴 Recording <#{$voiceChannel->id}>. "
                        . ($session->wakeWord === '' ? 'I answer everything that is said.' : "Say \"{$session->wakeWord}\" to talk to me.")
                        . ' Use /stop to end the recording, or /optout if you don\'t want to be recorded.'
                        . ' I remember each group\'s calls: see what I remember with /memory, and delete it with /forget.'
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

    /**
     * Refuses to record when the list of who opted out can't be read: without it, someone who
     * opted out would be recorded.
     */
    private function optOutsProblem(Interaction $interaction): ?string
    {
        try {
            (new OptOuts())->all();
        } catch (Throwable $e) {
            return $this->optOutsUnreadable($e, $interaction);
        }

        return null;
    }

    private function optOutsUnreadable(Throwable $e, Interaction $interaction): string
    {
        $this->log->error('Could not read who opted out of recording: ' . $e->getMessage(), ['guild' => $interaction->guild_id]);

        return 'I can\'t check who opted out of recording right now. Check the bot logs.';
    }
}
