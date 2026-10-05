<?php

declare(strict_types=1);

namespace App\Events;

use App\EventAbstract;
use App\Voice\Meeting;
use Discord\Discord;
use Discord\Parts\WebSockets\VoiceStateUpdate as VoiceState;

final class VoiceStateUpdate extends EventAbstract
{
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
