<?php

declare(strict_types=1);

namespace App\Voice;

use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Guild\Guild;
use Discord\Parts\WebSockets\VoiceStateUpdate;
use React\EventLoop\TimerInterface;
use Throwable;

/**
 * A private voice channel that /meet made for the people invited and the bot.
 *
 * It ends when the last person leaves the channel, or when nobody is in it once the time to join
 * is up: the call recorded in it is stopped, as /stop does, and the channel is deleted. The bot
 * is not one of the people, and neither is any other bot.
 */
final class Meeting
{
    /** Seconds the people invited have to join, before a channel nobody is in is deleted. */
    public const float JOIN_SECONDS = 300.0;

    /** @var array<string, self> The meetings that aren't over, by their channel's ID. */
    private static array $meetings = [];

    /** @var array<string, true> The people in the channel, by user ID. */
    private array $present = [];

    /** The call that records the meeting, once the bot joined the channel. */
    private ?VoiceSession $session = null;

    private TimerInterface $timer;

    /** Whether the time the people invited have to join is up. */
    private bool $late = false;

    private function __construct(
        private readonly Channel $channel,
        private readonly Guild $guild,
        private readonly Discord $discord,
        private readonly int $invited,
    ) {
    }

    /**
     * Follows a channel from the moment it is made, so that whoever joins it before the bot does counts too.
     *
     * @param int $invited How many people were invited, for the logs.
     */
    public static function open(Channel $channel, Guild $guild, Discord $discord, int $invited): self
    {
        $meeting = new self($channel, $guild, $discord, $invited);
        $meeting->timer = $discord->getLoop()->addTimer(self::JOIN_SECONDS, function () use ($meeting) {
            $meeting->late = true;

            if ($meeting->present === []) {
                $meeting->end();
            }
        });

        return self::$meetings[$channel->id] = $meeting;
    }

    /**
     * Tells every meeting that someone joined, moved between or left voice channels.
     */
    public static function follow(VoiceStateUpdate $state): void
    {
        foreach (self::$meetings as $meeting) {
            $meeting->see($state);
        }
    }

    /**
     * The bot joined the channel and records it with this call.
     *
     * @return bool False when the meeting is already over: the bot took longer to join than the
     *              people invited had to, and nobody was in the channel by then. The call is stopped then.
     */
    public function recordedBy(VoiceSession $session): bool
    {
        if (! isset(self::$meetings[$this->channel->id])) {
            $session->stop();

            return false;
        }

        $this->session = $session;
        $this->log('info', 'Meeting started');

        return true;
    }

    /**
     * Stops recording and deletes the channel. Safe to call more than once.
     */
    public function end(): void
    {
        if (! isset(self::$meetings[$this->channel->id])) {
            return;
        }

        unset(self::$meetings[$this->channel->id]);
        $this->discord->getLoop()->cancelTimer($this->timer);

        try {
            // The meeting only started once the bot joined: there is no call when it couldn't.
            if ($this->session !== null) {
                // Already stopped when someone used /stop, or disconnected the bot.
                $this->session->stop();
                $this->log('info', 'Meeting ended');
            }
        } finally {
            // Also when the call couldn't be stopped: nothing else would delete a channel only its people see.
            $this->guild->channels->delete($this->channel)->catch(function (Throwable $e) {
                $this->log('warning', 'Could not delete the meeting\'s channel: ' . $e->getMessage());
            });
        }
    }

    private function see(VoiceStateUpdate $state): void
    {
        $userId = (string) $state->user_id;

        if ($state->channel_id === $this->channel->id) {
            // Discord may not have said yet that the bot is a bot, so it is known by its ID.
            if ($userId !== $this->discord->id && ! $state->user?->bot) {
                $this->present[$userId] = true;
            }

            return;
        }

        if (! isset($this->present[$userId])) {
            return;
        }

        unset($this->present[$userId]);

        // While the bot is still joining, the people invited keep what is left of the time they have to join.
        if ($this->present === [] && ($this->session !== null || $this->late)) {
            $this->end();
        }
    }

    /**
     * Logs a step of the meeting, with what identifies it. The channel's name is left out: it holds people's names.
     */
    private function log(string $level, string $message): void
    {
        $this->discord->getLogger()->log($level, $message, [
            'guild' => $this->guild->id,
            'channel' => $this->channel->id,
            'invited' => $this->invited,
            'session' => $this->session?->id,
        ]);
    }
}
