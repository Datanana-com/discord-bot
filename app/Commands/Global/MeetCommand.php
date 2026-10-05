<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\CommandAbstract;
use App\Commands\RecordsCalls;
use App\Settings\GuildSettings;
use App\Voice\Meeting;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Http\Exceptions\NoPermissionsException;
use Discord\Parts\Application\Command\Option;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Overwrite;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Permissions\Permission;
use Throwable;

/**
 * Discord doesn't let bots join the calls of direct messages. A private voice channel in a
 * server, for just the people invited and the bot, is the closest there is.
 */
final class MeetCommand extends CommandAbstract
{
    use RecordsCalls;

    public string $description = 'Starts a private voice meeting with the people you pick, which I join and record.';

    public array $options = [
        ['type' => Option::USER, 'name' => 'person', 'description' => 'Who to meet with.', 'required' => true],
        ['type' => Option::USER, 'name' => 'person2', 'description' => 'Someone else to invite.'],
        ['type' => Option::USER, 'name' => 'person3', 'description' => 'Someone else to invite.'],
        ['type' => Option::USER, 'name' => 'person4', 'description' => 'Someone else to invite.'],
    ];

    /** What the people in a meeting and the bot may do in its channel: View Channel, Connect and Speak. */
    private const int ACCESS = (1 << Permission::VIEW_CHANNEL) | (1 << Permission::CONNECT) | (1 << Permission::SPEAK);

    /** The types of channel that are threads, whichever class DiscordPHP gives them: it only knows the threads it was sent. */
    private const array THREADS = [Channel::TYPE_ANNOUNCEMENT_THREAD, Channel::TYPE_PUBLIC_THREAD, Channel::TYPE_PRIVATE_THREAD];

    /** Characters that fit in a channel's name. */
    private const int NAME_LIMIT = 100;

    private const string MISSING_PERMISSION = 'I can\'t make the meeting\'s channel: I need the Manage Channels permission, besides View Channels, Connect and Speak. Ask a server admin to give it to me.';

    public function handle(Interaction $interaction): void
    {
        $guild = $interaction->guild;

        if ($guild === null) {
            $this->refuse($interaction, '/meet', 'Use /meet in a server.');

            return;
        }

        // Read once, so the call starts with the settings that are checked and announced here.
        $settings = (new GuildSettings($this->log))->for((string) $interaction->guild_id);

        $problem = $this->recordingProblem($interaction, $settings);

        if ($problem !== null) {
            $this->refuse($interaction, '/meet', $problem);

            return;
        }

        $invited = $this->invited($interaction);

        // Making the channel and joining it can take longer than the 3 seconds Discord waits for a response.
        $this->starting($interaction, fn () => $interaction->acknowledgeWithResponse()
            ->then(fn () => $guild->channels->save($guild->channels->create($this->channel($interaction, $guild, $invited))))
            ->then(
                fn (Channel $channel) => $this->meet($interaction, $guild, $channel, $invited, $settings),
                function (Throwable $e) use ($interaction) {
                    $this->log->error('Could not make the meeting\'s channel: ' . $e->getMessage(), ['guild' => $interaction->guild_id]);

                    return $interaction->updateOriginalResponse(MessageBuilder::new()->setContent(
                        // Only Discord knows whether the bot may: a category can allow what its roles don't. It also
                        // refuses when the bot lacks a permission it gives the people in the meeting.
                        $e instanceof NoPermissionsException ? self::MISSING_PERMISSION : 'Could not make the meeting\'s channel: ' . $e->getMessage()
                    ));
                },
            ));
    }

    /**
     * Joins the meeting's channel, records it and tells the people invited.
     *
     * @param list<string> $invited
     * @param array{wake_word: ?string, language: ?string, voice: ?string, model: ?string} $settings
     */
    private function meet(Interaction $interaction, Guild $guild, Channel $channel, array $invited, array $settings)
    {
        // From now on: the people invited can already see the channel, and join it before the bot has.
        $meeting = Meeting::open($channel, $guild, $this->discord, count($invited));

        // Like /record, answers and the summary go where the command was used: the meeting's own chat is deleted with it.
        return $this->record($interaction, $channel, $interaction->channel ?? $channel, $settings)->then(
            function (VoiceSession $session) use ($interaction, $channel, $meeting, $invited) {
                if (! $meeting->recordedBy($session)) {
                    return $interaction->updateOriginalResponse(MessageBuilder::new()->setContent('The meeting was over before I could join it, so I deleted its channel.'));
                }

                return $interaction
                    ->updateOriginalResponse(MessageBuilder::new()->setContent(
                        "🔴 Recording the meeting in <#{$channel->id}>. "
                        . $this->howToTalk($session)
                        . ' It ends when everyone has left, and its channel is deleted. Use /optout if you don\'t want to be recorded.'
                    ))
                    // In a message of its own: Discord doesn't notify the people mentioned in a response that was edited.
                    ->then(fn () => $invited === [] ? null : $interaction->sendFollowUpMessage(
                        MessageBuilder::new()
                            ->setContent(
                                implode(' ', array_map(fn (string $id) => "<@{$id}>", $invited))
                                . " You are invited to the meeting in <#{$channel->id}>. It is recorded: use /optout if you don't want to be."
                            )
                            // Only the people invited are pinged.
                            ->setAllowedMentions(['parse' => [], 'users' => $invited])
                    ));
            },
            function (Throwable $e) use ($interaction, $meeting) {
                // A channel nobody can meet in is not left behind.
                $meeting->end();

                return $interaction->updateOriginalResponse(MessageBuilder::new()->setContent($e->getMessage()));
            },
        );
    }

    /**
     * The people invited, by user ID: each once, and neither whoever used the command nor the bot,
     * who are in the meeting anyway.
     *
     * @return list<string>
     */
    private function invited(Interaction $interaction): array
    {
        $ids = [];

        foreach ($interaction->data?->options ?? [] as $option) {
            $ids[] = (string) $option->value;
        }

        return array_values(array_diff(array_unique($ids), [(string) $interaction->user?->id, (string) $this->discord->id]));
    }

    /**
     * The voice channel to make: named after the people in the meeting, and only they and the bot can see and join it.
     *
     * @param list<string> $invited
     * @return array<string, mixed>
     */
    private function channel(Interaction $interaction, Guild $guild, array $invited): array
    {
        $users = $interaction->data?->resolved?->users;
        $names = array_filter(
            [
                $interaction->member?->displayname,
                // By the name they go by in the server, when the bot knows it.
                ...array_map(fn (string $id) => $guild->members->get('id', $id)?->displayname ?? $users?->get('id', $id)?->displayname, $invited),
            ],
            fn (?string $name) => $name !== null,
        );
        $source = $interaction->channel;

        return [
            'name' => mb_substr('Meeting: ' . implode(', ', $names), 0, self::NAME_LIMIT),
            'type' => Channel::TYPE_GUILD_VOICE,
            // In the category of the channel the command was used in. A thread is in a channel, which is in the category.
            'parent_id' => in_array($source?->type, self::THREADS, true) ? $guild->channels->get('id', $source->parent_id)?->parent_id : $source?->parent_id,
            'permission_overwrites' => [
                // The role everyone has is named after the server.
                ['id' => (string) $guild->id, 'type' => Overwrite::TYPE_ROLE, 'allow' => 0, 'deny' => 1 << Permission::VIEW_CHANNEL],
                ...array_map(
                    fn (string $id) => ['id' => $id, 'type' => Overwrite::TYPE_MEMBER, 'allow' => self::ACCESS, 'deny' => 0],
                    [(string) $this->discord->id, (string) $interaction->user?->id, ...$invited],
                ),
            ],
        ];
    }
}
