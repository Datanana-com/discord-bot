<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\CommandAbstract;
use App\Settings\GuildSettings;
use App\Voice\Claude;
use App\Voice\Speech;
use App\Voice\Transcriber;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Application\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Permissions\Permission;

final class SettingsCommand extends CommandAbstract
{
    public string $description = "Shows or changes this server's wake word, language, voice and Claude model.";

    public array $options = [
        [
            'type' => Option::STRING,
            'name' => 'wake_word',
            'description' => 'What to say to talk to Claude: a word or phrase, up to 5 spellings separated by commas, or "none".',
            'max_length' => 200,
        ],
        [
            'type' => Option::STRING,
            'name' => 'language',
            'description' => 'The language spoken in calls: a code such as "en" or "pt", or "auto" to detect it.',
        ],
        [
            'type' => Option::STRING,
            'name' => 'voice',
            'description' => 'The Piper voice that speaks the answers, e.g. "pt_BR-faber-medium".',
        ],
        [
            'type' => Option::STRING,
            'name' => 'model',
            'description' => 'The Claude model that answers.',
            'choices' => [
                ['name' => 'haiku', 'value' => 'haiku'],
                ['name' => 'sonnet', 'value' => 'sonnet'],
                ['name' => 'opus', 'value' => 'opus'],
            ],
        ],
        [
            'type' => Option::BOOLEAN,
            'name' => 'reset',
            'description' => "Goes back to the bot's default settings.",
        ],
    ];

    /** Manage Server. */
    public ?int $defaultMemberPermissions = 1 << Permission::MANAGE_GUILD;

    public function handle(Interaction $interaction): void
    {
        // Discord refuses messages over 2000 characters, and many voices can be installed.
        $reply = mb_substr($this->reply($interaction), 0, 2000);

        $interaction->respondWithMessage(MessageBuilder::new()->setContent($reply), ephemeral: true);
    }

    private function reply(Interaction $interaction): string
    {
        $guildId = $interaction->guild_id;

        if ($guildId === null) {
            return 'Use /settings in a server.';
        }

        // Discord only shows the command to members with Manage Server, but server admins can change who sees it.
        $permissions = $interaction->member?->permissions;

        if (! $permissions?->manage_guild && ! $permissions?->administrator) {
            return 'You need the Manage Server permission to use /settings.';
        }

        $store = new GuildSettings($this->log);
        $current = $store->find($guildId);

        if ($current === null) {
            return 'The settings are not available right now. Check the bot logs.';
        }

        $given = [];

        foreach ($interaction->data?->options ?? [] as $option) {
            $given[$option->name] = $option->value;
        }

        $reset = ($given['reset'] ?? false) === true;
        // Discord can still offer an option of another version of the command: it is ignored.
        $values = self::tidy(array_intersect_key($given, GuildSettings::DEFAULTS));

        if (! $reset && $values === []) {
            return "**This server's settings**\n" . self::describe($current);
        }

        // Nothing is saved unless every value can be.
        if (($problems = self::problems($values)) !== []) {
            return implode("\n", $problems);
        }

        if (isset($values['wake_word'])) {
            // "none" stands for no wake word, as Discord doesn't let an option be empty.
            $values['wake_word'] = strcasecmp($values['wake_word'], 'none') === 0
                ? ''
                : implode(', ', VoiceSession::spellings($values['wake_word']));
        }

        $settings = [...($reset ? GuildSettings::DEFAULTS : $current), ...$values];

        if (! $store->save($guildId, $settings, (string) $interaction->user?->id)) {
            return 'The settings could not be saved. Check the bot logs.';
        }

        // Settings aren't speech, so the log says what they were changed to. Null is the default.
        $this->log->info('/settings changed', [
            'guild' => $guildId,
            'user' => $interaction->user?->id,
            'settings' => array_filter($settings, fn (?string $value, string $name) => $value !== $current[$name], ARRAY_FILTER_USE_BOTH),
        ]);

        return "**Saved this server's settings.** "
            . (VoiceSession::forGuild($guildId) === null
                ? 'They apply from the next call.'
                : 'The call in progress keeps its settings: these apply from the next call.')
            . "\n" . self::describe($settings);
    }

    /**
     * The given values as they are saved: without the spacing and capitals that don't belong in them.
     *
     * @param array<string, mixed> $values By setting.
     * @return array<string, string>
     */
    private static function tidy(array $values): array
    {
        // One space between words: a wake word is announced and shown the way it is saved.
        $values = array_map(fn (mixed $value) => trim(preg_replace('/\s+/u', ' ', (string) $value)), $values);

        if (isset($values['language'])) {
            $values['language'] = strtolower($values['language']);
        }

        return $values;
    }

    /**
     * Why the values that can't be saved are refused, each with what is allowed instead.
     *
     * @param array<string, string> $values By setting.
     * @return list<string>
     */
    private static function problems(array $values): array
    {
        $voices = Speech::voices();

        return array_values(array_filter([
            // Only what can be said: /record announces the wake word, where anything else could ping or format.
            // Each spelling is looked for as whole words, so it starts and ends with a letter or number.
            // A comma only separates spellings, so it can't ping or format either.
            isset($values['wake_word']) && strcasecmp($values['wake_word'], 'none') !== 0 && ! self::validSpellings($values['wake_word'])
                ? 'The wake word must be a word or short phrase, or up to 5 of them separated by commas for the ways whisper may write it. Each is at most 32 letters, numbers, spaces, apostrophes and hyphens, starting and ending with a letter or number. Use `none` to answer everything.'
                : null,
            isset($values['language']) && ! in_array($values['language'], ['auto', ...Transcriber::LANGUAGES], true)
                ? "The language must be `auto` or one of whisper's language codes: " . implode(', ', Transcriber::LANGUAGES) . '.'
                : null,
            // Only a name from the list, so a voice is always a file in the voices' folder.
            isset($values['voice']) && ! in_array($values['voice'], $voices, true)
                ? ($voices === []
                    ? "No Piper voices are installed in PIPER_MODEL's folder."
                    : 'The voice must be one of the installed Piper voices: `' . implode('`, `', $voices) . '`.')
                : null,
            isset($values['model']) && ! in_array($values['model'], Claude::MODELS, true)
                ? 'The model must be `haiku`, `sonnet` or `opus`.'
                : null,
        ]));
    }

    /**
     * Whether a wake word is one to five spellings that can each be said.
     */
    private static function validSpellings(string $wakeWord): bool
    {
        $spellings = VoiceSession::spellings($wakeWord);

        return $spellings !== [] && count($spellings) <= 5 && array_all(
            $spellings,
            fn (string $spelling) => preg_match('/^[\p{L}\p{N}]([\p{L}\p{M}\p{N}\' -]{0,30}[\p{L}\p{M}\p{N}])?$/u', $spelling) === 1,
        );
    }

    /**
     * A server's settings as its calls use them, one per line, saying which are the default.
     *
     * @param array{wake_word: ?string, language: ?string, voice: ?string, model: ?string} $settings
     */
    private static function describe(array $settings): string
    {
        $spellings = VoiceSession::spellings($settings['wake_word'] ?? VoiceSession::defaultWakeWord());
        $inUse = [
            'wake_word' => 'Wake word: ' . ($spellings === [] ? 'none, I answer everything that is said' : '`' . implode('`, `', $spellings) . '`'),
            'language' => 'Language: `' . Transcriber::fromEnv($settings['language'])->language . '`',
            'voice' => 'Voice: `' . basename(Speech::fromEnv($settings['voice'])->model, '.onnx') . '`',
            'model' => 'Claude model: `' . Claude::fromEnv($settings['model'])->model . '`',
        ];

        return implode("\n", array_map(
            fn (string $line, string $name) => $line . ($settings[$name] === null ? ' (default)' : ''),
            $inUse,
            array_keys($inUse),
        ));
    }
}
