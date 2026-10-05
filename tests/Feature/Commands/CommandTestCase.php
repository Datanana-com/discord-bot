<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use Discord\Builders\MessageBuilder;
use Discord\Parts\Application\Command\Option;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Guild\Member\Member;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Part;
use React\Promise\PromiseInterface;
use Tests\Feature\VoiceTestCase;

use function React\Promise\resolve;

abstract class CommandTestCase extends VoiceTestCase
{
    /** @var list<array{content: string, ephemeral: bool}> Immediate responses to the command. */
    protected array $responses = [];

    protected bool $acknowledged = false;

    /** @var list<string> Edits of the deferred response. */
    protected array $updates = [];

    /** @var list<array{content: string, ephemeral: bool}> Messages sent after the response. */
    protected array $followUps = [];

    /**
     * A slash command used by a member who is in the given voice channel, or in none.
     *
     * @param string|null $guildId The server it was used in, or null for a direct message.
     * @param string      $userId  Who used it: Alice, unless told otherwise.
     * @param array<string, string> $users     The people it names, as the user options /memory and /forget take: the option's name and their ID.
     * @param array<string, string> $nicknames What the server calls its members, by ID, when it isn't their name.
     * @param Part|null   $channel The channel or thread it was used in, when DiscordPHP knows it.
     * @param array<string, string> $choices   The text options it was given, as the options /privacy takes: the option's name and its value.
     */
    protected function interaction(?Channel $voiceChannel, ?string $guildId = self::GUILD_ID, string $userId = '555', array $users = [], array $nicknames = [], ?Part $channel = null, array $choices = []): Interaction
    {
        $member = static::getStubBuilder(Member::class)->disableOriginalConstructor()->onlyMethods(['getVoiceChannel'])->getStub();
        $member->method('getVoiceChannel')->willReturn($voiceChannel);

        $interaction = static::getStubBuilder(Interaction::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get', '__isset', 'respondWithMessage', 'acknowledgeWithResponse', 'updateOriginalResponse', 'sendFollowUpMessage'])
            ->getStub();
        $attributes = fn (string $name) => match ($name) {
            // Discord only sends the member for commands used in a server.
            'member' => $guildId === null ? null : $member,
            'user' => (object) ['id' => $userId],
            'guild_id' => $guildId,
            'channel' => $channel,
            // Discord leaves out the options when none were filled in.
            'data' => $users === [] && $choices === [] ? null : (object) ['options' => [
                ...array_map(
                    fn (string $name) => (object) ['name' => $name, 'type' => Option::USER, 'value' => $users[$name]],
                    array_keys($users),
                ),
                ...array_map(
                    fn (string $name) => (object) ['name' => $name, 'type' => Option::STRING, 'value' => $choices[$name]],
                    array_keys($choices),
                ),
            ]],
            'guild' => $nicknames === [] ? null : (object) ['members' => $this->userNames($nicknames)],
            default => null,
        };
        $interaction->method('__get')->willReturnCallback($attributes);
        $interaction->method('__isset')->willReturnCallback(fn (string $name) => $attributes($name) !== null);
        $interaction->method('respondWithMessage')->willReturnCallback(
            function (MessageBuilder $message, bool $ephemeral = false): PromiseInterface {
                $this->responses[] = ['content' => $message->getContent(), 'ephemeral' => $ephemeral];

                return resolve(null);
            }
        );
        $interaction->method('acknowledgeWithResponse')->willReturnCallback(function (): PromiseInterface {
            $this->acknowledged = true;

            return resolve(null);
        });
        $interaction->method('updateOriginalResponse')->willReturnCallback(function (MessageBuilder $message): PromiseInterface {
            $this->updates[] = $message->getContent();

            return resolve(null);
        });
        $interaction->method('sendFollowUpMessage')->willReturnCallback(
            function (MessageBuilder $message, bool $ephemeral = false): PromiseInterface {
                $this->followUps[] = ['content' => $message->getContent(), 'ephemeral' => $ephemeral];

                return resolve(null);
            }
        );

        return $interaction;
    }
}
