<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\Assistant\Memory;
use App\CommandAbstract;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use React\Promise\PromiseInterface;

final class ShareCommand extends CommandAbstract
{
    public string $description = 'Lets the bot use your personal memory for everyone in the call it is recording.';

    public function handle(Interaction $interaction): ?PromiseInterface
    {
        $userId = (string) $interaction->user->id;
        $voiceChannel = $interaction->member?->getVoiceChannel();
        $session = VoiceSession::forGuild((string) $interaction->guild_id);

        $reply = match (true) {
            $session === null || $voiceChannel === null || ! $session->records($voiceChannel)
                => 'Sharing your memory only works in a call I am recording: join its voice channel and use /share there.',
            $session->hasOptedOut($userId)
                => 'You opted out of being recorded, so I don\'t use your memory in calls either. Use /optin first, then /share.',
            Memory::fromEnv()->read($userId) === ''
                => "I don't remember anything about you yet, so there is nothing to share. Send me a direct message to chat with me.",
            ! $session->share($userId)
                => 'You are already sharing your memory with this call. Use /unshare to take it back.',
            default => 'Your memory is shared with this call: I may use it to answer anyone here, and to compare people\'s points of view.'
                . ' It stops when the call ends, or when you use /unshare. A notice goes to the call\'s text channel.',
        };

        return $interaction->respondWithMessage(MessageBuilder::new()->setContent($reply), ephemeral: true);
    }
}
