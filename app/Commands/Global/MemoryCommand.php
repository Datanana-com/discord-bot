<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\Assistant\Memory;
use App\CommandAbstract;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use React\Promise\PromiseInterface;

final class MemoryCommand extends CommandAbstract
{
    public string $description = 'Shows what the bot remembers about you.';

    public function handle(Interaction $interaction): void
    {
        $memory = Memory::fromEnv()->read((string) $interaction->user->id);

        if ($memory === '') {
            $interaction->respondWithMessage(
                MessageBuilder::new()->setContent("I don't remember anything about you yet. Send me a direct message to chat with me."),
                ephemeral: true,
            );

            return;
        }

        // A memory can be longer than a Discord message: the rest follows, also only for whoever asked.
        $parts = VoiceSession::split("**What I remember about you**\n{$memory}");

        array_reduce(
            array_slice($parts, 1),
            fn (PromiseInterface $sent, string $part) => $sent->then(
                fn () => $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent($part), ephemeral: true),
            ),
            $interaction->respondWithMessage(MessageBuilder::new()->setContent($parts[0]), ephemeral: true),
        );
    }
}
