<?php

declare(strict_types=1);

namespace App\Commands;

use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Interactions\Request\Option;

/**
 * A command with an option that Discord completes while someone types it: the option has 'autocomplete' => true.
 *
 * {@see \App\Application::handleGlobalCommands()} registers suggest() for the command, and tells nobody when it throws:
 * an option with nothing to suggest is still one that can be typed.
 */
interface SuggestsOptions
{
    /**
     * What to offer for the option that is being typed, which holds what has been typed so far.
     *
     * @param Option|null $focused Null when Discord names no option.
     * @return list<array{name: string, value: string}> At most 25: Discord refuses more. A name is shown, its value is used.
     */
    public function suggest(Interaction $interaction, ?Option $focused): array;
}
