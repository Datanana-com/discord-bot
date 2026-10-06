<?php

declare(strict_types=1);

namespace App\Events;

use App\EventAbstract;
use App\Voice\Meeting;
use App\Voice\VoiceSession;
use Discord\Discord;
use Discord\Parts\WebSockets\VoiceStateUpdate as VoiceState;

final class VoiceStateUpdate extends EventAbstract
{
    /**
     * Stops what is looked up in a call that is no longer wanted now that someone joined it: Discord's
     * voice states, which tell who is in the call, are up to date by the time the event is emitted.
     * It comes first: when a method fails, the ones after it are not run.
     *
     * @param VoiceState $state   Where someone is now: in which voice channel, or in none
     * @param Discord    $discord Discord class
     *
     * @return void
     */
    public function dropLookups(VoiceState $state, Discord $discord)
    {
        VoiceSession::peopleMoved((string) $state->guild_id);
    }

    /**
     * Has the meetings made by /meet end once everyone has left them.
     *
     * @param VoiceState $state   Where someone is now: in which voice channel, or in none
     * @param Discord    $discord Discord class
     *
     * @return void
     */
    public function followMeetings(VoiceState $state, Discord $discord)
    {
        Meeting::follow($state);
    }
}
