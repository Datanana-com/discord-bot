<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\Assistant\Memory;
use App\Assistant\MemoryGroup;
use App\CommandAbstract;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use React\Promise\PromiseInterface;

final class MemoryCommand extends CommandAbstract
{
    public string $description = 'Shows what the bot remembers about you, or about you and the people you have calls with.';

    public array $options = MemoryGroup::OPTIONS;

    public function handle(Interaction $interaction): void
    {
        $userId = (string) $interaction->user->id;
        $store = Memory::fromEnv();
        $others = MemoryGroup::others($interaction);

        if ($others !== []) {
            $who = 'you and ' . MemoryGroup::names($others, $interaction, $this->discord);
            $memory = $store->read([$userId, ...$others]);
            $reply = $memory === ''
                ? "I don't remember anything about {$who} together yet. I remember what is said in my calls with a group of people."
                : "**What I remember about {$who}**\n{$memory}";
        } else {
            $memory = $store->read($userId);
            $reply = $memory === ''
                ? "I don't remember anything about you yet. Send me a direct message to chat with me."
                : "**What I remember about you**\n{$memory}";
            $groups = array_map(
                fn (array $group) => MemoryGroup::names(array_values(array_diff($group, [$userId])), $interaction, $this->discord),
                $store->groups($userId),
            );

            if ($groups !== []) {
                $reply .= "\n\nYou also have memories with: " . implode('; ', $groups) . '.';
            }
        }

        // A memory can be longer than a Discord message: the rest follows, also only for whoever asked.
        $parts = VoiceSession::split($reply);

        array_reduce(
            array_slice($parts, 1),
            fn (PromiseInterface $sent, string $part) => $sent->then(
                fn () => $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent($part), ephemeral: true),
            ),
            $interaction->respondWithMessage(MessageBuilder::new()->setContent($parts[0]), ephemeral: true),
        );
    }
}
