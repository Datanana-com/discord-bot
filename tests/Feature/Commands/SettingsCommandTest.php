<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Analytics\Usage;
use App\Commands\Global\SettingsCommand;
use App\Settings\GuildSettings;
use App\Voice\Transcriber;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Application\Command\Command;
use Discord\Parts\Application\Command\Option;
use Discord\Parts\Interactions\ApplicationCommand;
use Discord\Parts\Interactions\Interaction;
use Illuminate\Database\Capsule\Manager as DB;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

final class SettingsCommandTest extends CommandTestCase
{
    /** A member's permissions, as the bit set Discord sends with the command. */
    private const string MANAGE_SERVER = '32';

    private const string ADMINISTRATOR = '8';

    private const string SEND_MESSAGES = '2048';

    private GuildSettings $store;

    protected function setUp(): void
    {
        parent::setUp();

        // Two voices are installed: the one in .env ("voice") and this one.
        touch("{$this->recordings}/models/pt_BR-faber-medium.onnx");
        touch("{$this->recordings}/models/pt_BR-faber-medium.onnx.json");
        $this->store = new GuildSettings(new Logger('test'));
    }

    public function testShowsTheServersSettingsAndWhichAreDefaults(): void
    {
        $this->assertSame(
            "**This server's settings**\nWake word: `claude` (default)\nLanguage: `auto` (default)\nVoice: `voice` (default)\nClaude model: `haiku` (default)",
            $this->settings(),
        );

        $this->store->save(self::GUILD_ID, [...GuildSettings::DEFAULTS, 'language' => 'pt', 'voice' => 'pt_BR-faber-medium'], '555');

        $this->assertSame(
            "**This server's settings**\nWake word: `claude` (default)\nLanguage: `pt`\nVoice: `pt_BR-faber-medium`\nClaude model: `haiku` (default)",
            $this->settings(),
        );
        $this->assertSame([], $this->logged('/settings changed'), 'Looking at the settings changes nothing.');
    }

    public function testTheDefaultsAreTheOnesInEnv(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => '', 'WHISPER_LANGUAGE' => 'en', 'CLAUDE_MODEL' => 'opus']);

        $this->assertSame(
            "**This server's settings**\nWake word: none, I answer everything that is said (default)\nLanguage: `en` (default)\nVoice: `voice` (default)\nClaude model: `opus` (default)",
            $this->settings(),
        );
    }

    public function testSavesTheGivenSettings(): void
    {
        $reply = $this->settings(['wake_word' => 'Hey Jarvis', 'language' => 'pt', 'voice' => 'pt_BR-faber-medium', 'model' => 'sonnet']);

        $this->assertSame(
            "**Saved this server's settings.** They apply from the next call.\n"
            . "Wake word: `Hey Jarvis`\nLanguage: `pt`\nVoice: `pt_BR-faber-medium`\nClaude model: `sonnet`",
            $reply,
        );
        $this->assertSame(
            ['wake_word' => 'Hey Jarvis', 'language' => 'pt', 'voice' => 'pt_BR-faber-medium', 'model' => 'sonnet'],
            $this->store->find(self::GUILD_ID),
        );
        $this->assertSame('555', DB::connection(Usage::CONNECTION)->table('guild_settings')->value('updated_by'));

        // Settings aren't speech, so the log says what they were changed to.
        $this->assertSame(
            [[
                'guild' => self::GUILD_ID,
                'user' => '555',
                'settings' => ['wake_word' => 'Hey Jarvis', 'language' => 'pt', 'voice' => 'pt_BR-faber-medium', 'model' => 'sonnet'],
            ]],
            $this->logged('/settings changed'),
        );
        $this->assertSame([], $this->loggedProblems());
    }

    public function testChangesOnlyWhatWasGiven(): void
    {
        $this->settings(['language' => 'pt', 'model' => 'opus']);

        $reply = $this->settings(['model' => 'sonnet']);

        $this->assertSame(['wake_word' => null, 'language' => 'pt', 'voice' => null, 'model' => 'sonnet'], $this->store->find(self::GUILD_ID));
        $this->assertStringEndsWith("Wake word: `claude` (default)\nLanguage: `pt`\nVoice: `voice` (default)\nClaude model: `sonnet`", $reply);
        $this->assertSame(['model' => 'sonnet'], $this->logged('/settings changed')[1]['settings'], 'Only what changed is logged.');
    }

    public function testNoneAsTheWakeWordAnswersEverything(): void
    {
        // Discord doesn't let an option be empty.
        $reply = $this->settings(['wake_word' => 'None']);

        $this->assertSame('', $this->store->find(self::GUILD_ID)['wake_word']);
        $this->assertStringContainsString("\nWake word: none, I answer everything that is said\n", $reply);
        $this->assertTrue(VoiceSession::mentions('What time is it?', $this->store->for(self::GUILD_ID)['wake_word']));
    }

    /**
     * @param string $wakeWord A wake word that can be said, and found in a transcript.
     */
    #[DataProvider('wakeWords')]
    public function testAcceptsWakeWordsThatCanBeSaid(string $wakeWord, string $said): void
    {
        $this->settings(['wake_word' => $wakeWord]);

        $saved = $this->store->find(self::GUILD_ID)['wake_word'];
        $this->assertSame($wakeWord, $saved);
        $this->assertTrue(VoiceSession::mentions($said, $saved), 'A call answers to it.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function wakeWords(): iterable
    {
        yield 'one letter' => ['k', 'K, what time is it?'];
        yield 'a phrase, heard with a pause' => ['okay computer', 'Okay, computer, what time is it?'];
        yield 'a hyphen inside' => ['Jean-Luc', 'Hey Jean-Luc, what time is it?'];
        yield 'an apostrophe inside' => ["d'Artagnan", "What do you think, d'Artagnan?"];
        yield 'numbers' => ['r2d2', 'R2D2, what time is it?'];
        yield 'accents' => ['José', 'Olá José, que horas são?'];
        yield 'another script' => ['クロード', 'ねえ クロード 今何時'];
        yield 'a script that writes vowels as signs' => ['राजा', 'राजा, समय क्या है?'];
        yield 'as long as it can be' => [str_repeat('a', 32), 'Hey ' . str_repeat('a', 32) . '!'];
    }

    public function testSavesSeveralSpellingsOfTheWakeWordAndShowsThem(): void
    {
        $reply = $this->settings(['wake_word' => ' Jarvis ,service,  Hey   Jarvis, jarvis,, ']);

        $this->assertSame('Jarvis, service, Hey Jarvis', $this->store->find(self::GUILD_ID)['wake_word'], 'One space after each comma, and a repeated spelling once.');
        $this->assertStringContainsString("
Wake word: `Jarvis`, `service`, `Hey Jarvis`
", $reply, 'The name first.');
        $this->assertTrue(VoiceSession::mentions('Hey, Service, what time is it?', $this->store->for(self::GUILD_ID)['wake_word']));
        $this->assertSame(['wake_word' => 'Jarvis, service, Hey Jarvis'], $this->logged('/settings changed')[0]['settings']);
    }

    public function testShowsEverySpellingOfTheDefaultWakeWord(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => ' claude ,cloud, claud']);

        $this->assertStringContainsString("
Wake word: `claude`, `cloud`, `claud` (default)
", $this->settings([]));
    }

    public function testTakesUpToFiveSpellings(): void
    {
        $this->settings(['wake_word' => 'one, two, three, four, five']);

        $this->assertSame('one, two, three, four, five', $this->store->find(self::GUILD_ID)['wake_word']);
    }

    public function testRefusesASixthSpellingWithoutChangingAnything(): void
    {
        $this->settings(['wake_word' => 'jarvis, service', 'language' => 'pt']);

        $reply = $this->settings(['wake_word' => 'one, two, three, four, five, six', 'language' => 'en']);

        $this->assertStringContainsString('up to 5 of them separated by commas', $reply);
        $this->assertSame('jarvis, service', $this->store->find(self::GUILD_ID)['wake_word']);
        $this->assertSame('pt', $this->store->find(self::GUILD_ID)['language']);
    }

    public function testASpellingRepeatedInAnotherCaseDoesNotCountTowardsTheFive(): void
    {
        $this->settings(['wake_word' => 'one, two, three, four, five, FIVE, One']);

        $this->assertSame('one, two, three, four, five', $this->store->find(self::GUILD_ID)['wake_word']);
    }

    public function testNoneWithCommasAroundItStillMeansNoWakeWord(): void
    {
        $this->settings(['wake_word' => 'claude']);

        $this->settings(['wake_word' => ' None , ']);

        $this->assertSame('', $this->store->find(self::GUILD_ID)['wake_word']);
    }

    public function testNoneIsAWakeWordWhenAmongOthers(): void
    {
        $this->settings(['wake_word' => 'claude, none']);

        $this->assertSame('claude, none', $this->store->find(self::GUILD_ID)['wake_word']);
    }

    public function testACallKeepsTheSpellingsItStartedWith(): void
    {
        $this->store->save(self::GUILD_ID, [...GuildSettings::DEFAULTS, 'wake_word' => 'claude, cloud'], '777');
        $channel = $this->voiceChannel();
        $session = VoiceSession::start($this->voiceClient($channel), $channel, $this->discord);

        $this->settings(['wake_word' => 'jarvis']);

        $this->assertSame('claude, cloud', $session->wakeWord);
        $this->assertTrue(VoiceSession::mentions('Hey Cloud', $session->wakeWord));
        $this->assertFalse(VoiceSession::mentions('Hey Jarvis', $session->wakeWord));
    }

    public function testTidiesWhatWasTyped(): void
    {
        $this->settings(['wake_word' => '  okay   computer ', 'language' => ' PT ']);

        $settings = $this->store->find(self::GUILD_ID);
        $this->assertSame('okay computer', $settings['wake_word'], 'It is announced and shown as it is saved.');
        $this->assertSame('pt', $settings['language']);
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('invalidValues')]
    public function testRefusesAnInvalidValueWithWhatIsAllowed(array $options, string $expected): void
    {
        $reply = $this->settings($options);

        $this->assertSame($expected, $reply);
        $this->assertSame(GuildSettings::DEFAULTS, $this->store->find(self::GUILD_ID), 'Nothing was saved.');
        $this->assertSame([], $this->logged('/settings changed'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidValues(): iterable
    {
        $wakeWord = 'The wake word must be a word or short phrase, or up to 5 of them separated by commas for the ways whisper may write it. Each is at most 32 letters, numbers, spaces, apostrophes and hyphens, starting and ending with a letter or number. Use `none` to answer everything.';
        $voice = 'The voice must be one of the installed Piper voices: `pt_BR-faber-medium`, `voice`.';

        yield 'a wake word that is too long' => [['wake_word' => str_repeat('a', 33)], $wakeWord];
        yield 'a wake word that pings everyone when /record announces it' => [['wake_word' => '@everyone'], $wakeWord];
        yield 'a wake word that is formatted' => [['wake_word' => '**claude**'], $wakeWord];
        yield 'a wake word of spaces' => [['wake_word' => '   '], $wakeWord];
        yield 'a wake word of commas' => [['wake_word' => ' , ,'], $wakeWord];
        yield 'a sixth spelling' => [['wake_word' => 'claude, cloud, claud, clod, clawed, clowd'], $wakeWord];
        yield 'a spelling that is too long' => [['wake_word' => 'claude, ' . str_repeat('a', 33)], $wakeWord];
        yield 'a spelling that pings everyone' => [['wake_word' => 'claude, @everyone'], $wakeWord];
        yield 'a spelling that is formatted' => [['wake_word' => 'claude, **cloud**'], $wakeWord];
        yield 'a spelling that ends with a hyphen' => [['wake_word' => 'claude, cloud-'], $wakeWord];
        yield 'a spelling that starts with an apostrophe' => [['wake_word' => "claude, 'cloud"], $wakeWord];
        // A wake word is looked for as whole words, so these would be found inside "well-known" and "don't".
        yield 'a wake word that is only a hyphen' => [['wake_word' => '-'], $wakeWord];
        yield 'a wake word that is only an apostrophe' => [['wake_word' => "'"], $wakeWord];
        yield 'a wake word that ends with a hyphen' => [['wake_word' => 'jarvis-'], $wakeWord];
        yield 'a wake word that starts with an apostrophe' => [['wake_word' => "'cause"], $wakeWord];
        yield 'a language by its name' => [
            ['language' => 'portuguese'],
            "The language must be `auto` or one of whisper's language codes: " . implode(', ', Transcriber::LANGUAGES) . '.',
        ];
        yield 'a voice that is not installed' => [['voice' => 'en_GB-alan-medium'], $voice];
        yield 'a voice outside the voices folder' => [['voice' => '../models/voice'], $voice];
        yield 'a voice with its file extension' => [['voice' => 'voice.onnx'], $voice];
        yield 'a model that is not a Claude model' => [['model' => 'gpt-5'], 'The model must be `haiku`, `sonnet` or `opus`.'];
        yield 'several invalid values' => [
            ['wake_word' => '@here', 'model' => 'gpt-5'],
            "{$wakeWord}\nThe model must be `haiku`, `sonnet` or `opus`.",
        ];
    }

    public function testSavesNothingWhenOneOfTheValuesIsInvalid(): void
    {
        $reply = $this->settings(['language' => 'pt', 'model' => 'gpt-5']);

        $this->assertSame('The model must be `haiku`, `sonnet` or `opus`.', $reply);
        $this->assertSame(GuildSettings::DEFAULTS, $this->store->find(self::GUILD_ID));
    }

    public function testAcceptsEveryLanguageWhisperKnowsAndAuto(): void
    {
        foreach (['auto', 'en', 'pt', 'haw', 'yue'] as $language) {
            $this->settings(['language' => $language]);

            $this->assertSame($language, $this->store->find(self::GUILD_ID)['language']);
        }
    }

    public function testSaysWhenNoVoicesAreInstalled(): void
    {
        $this->setEnv(['PIPER_MODEL' => '/nowhere/voices/en_US-lessac-medium.onnx']);

        $this->assertSame("No Piper voices are installed in PIPER_MODEL's folder.", $this->settings(['voice' => 'pt_BR-faber-medium']));
    }

    public function testResetGoesBackToTheDefaults(): void
    {
        $this->settings(['wake_word' => 'jarvis', 'language' => 'pt']);

        $reply = $this->settings(['reset' => true]);

        $this->assertSame(
            "**Saved this server's settings.** They apply from the next call.\n"
            . "Wake word: `claude` (default)\nLanguage: `auto` (default)\nVoice: `voice` (default)\nClaude model: `haiku` (default)",
            $reply,
        );
        $this->assertSame(GuildSettings::DEFAULTS, $this->store->find(self::GUILD_ID));
        $this->assertSame(['wake_word' => null, 'language' => null], $this->logged('/settings changed')[1]['settings'], 'Null is the default.');
    }

    public function testResetThenAppliesTheOtherOptions(): void
    {
        $this->settings(['wake_word' => 'jarvis', 'language' => 'pt', 'model' => 'opus']);

        $this->settings(['reset' => true, 'model' => 'sonnet']);

        $this->assertSame(['wake_word' => null, 'language' => null, 'voice' => null, 'model' => 'sonnet'], $this->store->find(self::GUILD_ID));
    }

    public function testOnlyShowsTheSettingsWhenNotAskedToReset(): void
    {
        $this->settings(['language' => 'pt']);

        $reply = $this->settings(['reset' => false]);

        $this->assertStringStartsWith("**This server's settings**\n", $reply);
        $this->assertSame('pt', $this->store->find(self::GUILD_ID)['language']);
        $this->assertCount(1, $this->logged('/settings changed'));
    }

    public function testIgnoresOptionsFromAnotherVersionOfTheCommand(): void
    {
        // Discord can still offer an option this version doesn't have, until the command is saved again.
        $reply = $this->settings(['summaries' => true, 'model' => 'sonnet']);

        $this->assertStringStartsWith("**Saved this server's settings.**", $reply);
        $this->assertSame([...GuildSettings::DEFAULTS, 'model' => 'sonnet'], $this->store->find(self::GUILD_ID));
    }

    public function testSaysThatACallInProgressKeepsItsSettings(): void
    {
        $channel = $this->voiceChannel();
        VoiceSession::start($this->voiceClient($channel), $channel, $this->discord);

        $reply = $this->settings(['model' => 'opus']);

        $this->assertStringStartsWith(
            "**Saved this server's settings.** The call in progress keeps its settings: these apply from the next call.\n",
            $reply,
        );
    }

    #[DataProvider('membersWithoutManageServer')]
    public function testNeedsTheManageServerPermission(array $options): void
    {
        // Discord hides the command from them, unless a server admin changed who sees it.
        $this->store->save(self::GUILD_ID, [...GuildSettings::DEFAULTS, 'language' => 'pt'], '777');

        $reply = $this->settings($options, permissions: self::SEND_MESSAGES);

        $this->assertSame('You need the Manage Server permission to use /settings.', $reply);
        $this->assertSame([...GuildSettings::DEFAULTS, 'language' => 'pt'], $this->store->find(self::GUILD_ID));
        $this->assertSame([], $this->logged('/settings changed'));
    }

    /**
     * @return iterable<string, array{array<string, string|bool>}>
     */
    public static function membersWithoutManageServer(): iterable
    {
        yield 'looking at the settings' => [[]];
        yield 'changing a setting' => [['model' => 'opus']];
        yield 'resetting the settings' => [['reset' => true]];
    }

    public function testAdministratorsCanUseItToo(): void
    {
        // An administrator has every permission, whichever bits Discord sends for them.
        $this->settings(['model' => 'opus'], permissions: self::ADMINISTRATOR);

        $this->assertSame('opus', $this->store->find(self::GUILD_ID)['model']);
    }

    public function testOnlyWorksInAServer(): void
    {
        $this->assertSame('Use /settings in a server.', $this->settings(['model' => 'opus'], guildId: null));
    }

    public function testSaysWhenTheSettingsAreUnavailable(): void
    {
        $this->breakStatsDatabase();

        $this->assertSame('The settings are not available right now. Check the bot logs.', $this->settings(['model' => 'opus']));
        $this->assertSame(['Could not read the server settings: Database connection [stats] not configured.'], $this->loggedProblems());
        $this->assertSame([], $this->logged('/settings changed'));
    }

    public function testSaysWhenTheSettingsCannotBeSaved(): void
    {
        // The database can be read, but not written to.
        $this->store->find(self::GUILD_ID);
        DB::connection(Usage::CONNECTION)->statement('PRAGMA query_only = ON');

        $reply = $this->settings(['model' => 'opus']);

        $this->assertSame('The settings could not be saved. Check the bot logs.', $reply);
        $this->assertCount(1, $this->loggedProblems());
        $this->assertStringStartsWith('Could not save the server settings: ', $this->loggedProblems()[0]);
        $this->assertSame([], $this->logged('/settings changed'));
    }

    public function testKeepsLongRepliesWithinDiscordsLimit(): void
    {
        // Discord refuses messages over 2000 characters, and 150 installed voices don't fit in one.
        for ($i = 100; $i < 250; $i++) {
            touch("{$this->recordings}/models/en_US-voice-number-{$i}-medium.onnx");
        }

        $reply = $this->settings(['voice' => 'en_GB-alan-medium']);

        $this->assertSame(2000, mb_strlen($reply));
        $this->assertStringStartsWith('The voice must be one of the installed Piper voices: `en_US-voice-number-100-medium`, ', $reply);
    }

    /**
     * Uses /settings and returns the reply.
     *
     * The command arrives as Discord sends it and is read by DiscordPHP's own classes, so the
     * options and the member's permissions are found where they really are.
     *
     * @param array<string, string|bool> $options     The options the member filled in.
     * @param string                     $permissions The member's permissions in the channel, as a bit set.
     * @param string|null                $guildId     The server it was used in, or null for a direct message.
     */
    private function settings(array $options = [], string $permissions = self::MANAGE_SERVER, ?string $guildId = self::GUILD_ID): string
    {
        $user = (object) ['id' => '555', 'username' => 'alice'];
        $data = ['id' => '900', 'name' => 'settings', 'type' => Command::CHAT_INPUT];

        // Discord leaves out the options when none were filled in.
        if ($options !== []) {
            $data['options'] = array_map(
                fn (string $name) => (object) ['name' => $name, 'type' => is_bool($options[$name]) ? Option::BOOLEAN : Option::STRING, 'value' => $options[$name]],
                array_keys($options),
            );
        }

        $interaction = static::getStubBuilder(ApplicationCommand::class)
            ->setConstructorArgs([
                $this->client(),
                [
                    'id' => '901',
                    'type' => Interaction::TYPE_APPLICATION_COMMAND,
                    'token' => 'interaction-token',
                    'channel_id' => '200',
                    'data' => (object) $data,
                    ...($guildId === null
                        ? ['user' => $user]
                        : ['guild_id' => $guildId, 'member' => (object) ['user' => $user, 'roles' => [], 'permissions' => $permissions]]),
                ],
                true,
            ])
            ->onlyMethods(['respondWithMessage'])
            ->getStub();
        $interaction->method('respondWithMessage')->willReturnCallback(
            function (MessageBuilder $message, bool $ephemeral = false): PromiseInterface {
                $this->responses[] = ['content' => $message->getContent(), 'ephemeral' => $ephemeral];

                return resolve(null);
            }
        );

        $this->responses = [];
        (new SettingsCommand($this->discord))->handle($interaction);

        $this->assertCount(1, $this->responses);
        $this->assertTrue($this->responses[0]['ephemeral'], 'Only whoever used /settings sees the reply.');

        return $this->responses[0]['content'];
    }

    /**
     * A Discord client that never connects, for DiscordPHP to build the interaction with.
     */
    private function client(): Discord
    {
        return new Discord(['token' => 'test-token', 'loop' => new StreamSelectLoop(), 'logger' => new Logger('discord', [new NullHandler()])]);
    }
}
