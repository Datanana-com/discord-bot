<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Guild\Member\Member;
use Discord\Parts\Interactions\Interaction;
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

    /**
     * A slash command used by a member who is in the given voice channel, or in none.
     */
    protected function interaction(?Channel $voiceChannel): Interaction
    {
        $member = static::getStubBuilder(Member::class)->disableOriginalConstructor()->onlyMethods(['getVoiceChannel'])->getStub();
        $member->method('getVoiceChannel')->willReturn($voiceChannel);

        $interaction = static::getStubBuilder(Interaction::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get', '__isset', 'respondWithMessage', 'acknowledgeWithResponse', 'updateOriginalResponse'])
            ->getStub();
        $attributes = fn (string $name) => match ($name) {
            'member' => $member,
            'guild_id' => self::GUILD_ID,
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

        return $interaction;
    }
}
