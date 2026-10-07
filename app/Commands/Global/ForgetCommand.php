<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\Assistant\DirectChat;
use App\Assistant\Memory;
use App\Assistant\MemoryGroup;
use App\CommandAbstract;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use React\Promise\PromiseInterface;

final class ForgetCommand extends CommandAbstract
{
    public string $description = 'Deletes what the bot remembers about you, or about you and the people you have calls with.';

    public array $options = MemoryGroup::OPTIONS;

    public function handle(Interaction $interaction): ?PromiseInterface
    {
        $userId = (string) $interaction->user->id;
        $others = MemoryGroup::others($interaction);
        $people = [$userId, ...$others];

        // What they said since the memory was last updated would otherwise be remembered later.
        VoiceSession::forget($people);

        if ($others === []) {
            DirectChat::forget($userId);
        }

        $who = $others === [] ? 'you' : 'you and ' . MemoryGroup::names($others, $interaction, $this->discord);

        return $interaction->respondWithMessage(
            MessageBuilder::new()->setContent(
                Memory::fromEnv()->forget($people)
                    ? "Done: I forgot what I remembered about {$who}."
                    : "I don't remember anything about {$who}" . ($others === [] ? '' : ' together') . ', so there is nothing to forget.'
            ),
            ephemeral: true,
        );
    }
}
