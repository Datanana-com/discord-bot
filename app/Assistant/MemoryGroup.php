<?php

declare(strict_types=1);

namespace App\Assistant;

use Discord\Discord;
use Discord\Parts\Application\Command\Option;
use Discord\Parts\Interactions\Interaction;

/**
 * The people a /memory or /forget command names, besides whoever used it, to pick the memory they share.
 */
final class MemoryGroup
{
    /** What /memory and /forget take: the other people of the group, one in each option. */
    public const array OPTIONS = [
        ['type' => Option::USER, 'name' => 'with', 'description' => 'Someone in the group the memory belongs to.'],
        ['type' => Option::USER, 'name' => 'with2', 'description' => 'Another person in the group.'],
        ['type' => Option::USER, 'name' => 'with3', 'description' => 'Another person in the group.'],
        ['type' => Option::USER, 'name' => 'with4', 'description' => 'Another person in the group.'],
    ];

    /**
     * @return list<string> The user IDs of the people named, each once, without whoever used the command.
     */
    public static function others(Interaction $interaction): array
    {
        $names = array_column(self::OPTIONS, 'name');
        $named = [];

        foreach ($interaction->data?->options ?? [] as $option) {
            if (in_array($option->name, $names, true)) {
                $named[] = (string) $option->value;
            }
        }

        return array_values(array_diff(array_unique($named), [(string) $interaction->user->id]));
    }

    /**
     * Who the people are, as "Spartan", "Spartan and Carol" or "Spartan, Carol and Dan".
     *
     * @param list<string> $people User IDs.
     */
    public static function names(array $people, Interaction $interaction, Discord $discord): string
    {
        $names = array_map(
            // What the server calls them, else what Discord does, else a mention, which Discord shows by name.
            fn (string $userId) => $interaction->guild?->members->get('id', $userId)?->displayname
                ?? $discord->users->get('id', $userId)?->displayname
                ?? "<@{$userId}>",
            $people,
        );
        $last = array_pop($names);

        return $names === [] ? $last : implode(', ', $names) . " and {$last}";
    }
}
