<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\CommandAbstract;
use App\Settings\UserSettings;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Application\Command\Option;
use Discord\Parts\Interactions\Interaction;
use React\Promise\PromiseInterface;

final class PrivacyCommand extends CommandAbstract
{
    /** What each choice is called in Discord, by what is saved. */
    private const array NAMES = [
        UserSettings::WHEN_ASKED => 'when I ask',
        UserSettings::AFTER_SHARE => 'only after /share',
    ];

    public string $description = 'Shows or changes your privacy settings, such as when the bot may use your personal memory in calls.';

    public array $options = [
        [
            'type' => Option::STRING,
            'name' => 'personal_memory_in_calls',
            'description' => 'When I may use your personal memory in a call with other people.',
            'choices' => [
                ['name' => self::NAMES[UserSettings::WHEN_ASKED], 'value' => UserSettings::WHEN_ASKED],
                ['name' => self::NAMES[UserSettings::AFTER_SHARE], 'value' => UserSettings::AFTER_SHARE],
            ],
        ],
    ];

    public function handle(Interaction $interaction): ?PromiseInterface
    {
        return $interaction->respondWithMessage(MessageBuilder::new()->setContent($this->reply($interaction)), ephemeral: true);
    }

    private function reply(Interaction $interaction): string
    {
        $userId = (string) $interaction->user?->id;
        $store = new UserSettings($this->log);
        $current = $store->find($userId);
        $given = null;

        foreach ($interaction->data?->options ?? [] as $option) {
            if ($option->name === 'personal_memory_in_calls') {
                $given = (string) $option->value;
            }
        }

        if ($given === null) {
            return $current === null
                ? 'Your privacy settings are not available right now. Check the bot logs.'
                : "**Your privacy settings**\n" . self::describe($current);
        }

        // Discord can still offer a choice of another version of the command.
        if (! in_array($given, UserSettings::PERSONAL_MEMORY_IN_CALLS, true)) {
            return 'Personal memory in calls can be `' . implode('` or `', array_values(self::NAMES)) . '`.';
        }

        // A choice replaces what is saved, so it also repairs settings that can't be read: until then, calls keep this person's memory out.
        $settings = [...($current ?? UserSettings::DEFAULTS), 'personal_memory_in_calls' => $given];

        if (! $store->save($userId, $settings)) {
            return 'Your privacy settings could not be saved. Check the bot logs.';
        }

        // A choice isn't speech, so the log says what it was changed to.
        $this->log->info('/privacy changed', ['user' => $userId, 'personal_memory_in_calls' => $given]);

        return match ($given) {
            UserSettings::AFTER_SHARE => 'Your personal memory will only be used in calls with other people after you use /share.'
                . ' Calls where you are alone with me, group memories and direct messages work as before.',
            UserSettings::WHEN_ASKED => 'Your personal memory will be used in calls with other people whenever you ask me something.'
                . ' Use /privacy again to change that.',
        };
    }

    /**
     * A person's settings, one per line, saying which are the default.
     *
     * @param array{personal_memory_in_calls: string} $settings
     */
    private static function describe(array $settings): string
    {
        $memory = $settings['personal_memory_in_calls'];

        return 'Personal memory in calls with other people: `' . self::NAMES[$memory] . '`'
            . ($memory === UserSettings::DEFAULTS['personal_memory_in_calls'] ? ' (default)' : '')
            . ($memory === UserSettings::AFTER_SHARE
                ? ': I only use it after you use /share.'
                : ': I use it whenever you ask me something.');
    }
}
