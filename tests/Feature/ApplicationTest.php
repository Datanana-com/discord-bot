<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application;
use App\Commands\Global\RecallCommand;
use App\Commands\Global\SettingsCommand;
use App\Exceptions\EventNotFoundException;
use Closure;
use Discord\Discord;
use Discord\Parts\Application\Command\Command;
use Discord\Parts\Application\Command\Option;
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Interaction;
use Discord\Helpers\RegisteredCommand;
use Discord\WebSockets\Event;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use ReflectionClass;
use RuntimeException;
use Tests\Fixtures\Events\RecordingEvent;

use function React\Promise\reject;
use function React\Promise\resolve;

final class ApplicationTest extends TestCase
{
    private TestHandler $logs;

    /** @var list<string> Files and folders added to the app's folders, removed after each test. */
    private array $appFiles = [];

    /** Where the bot is told to keep its recordings, when a test needs some. */
    private ?string $recordings = null;

    private ?string $originalRecordingsPath = null;

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
        RecordingEvent::$calls = [];
        RecordingEvent::$before = null;
        $this->originalRecordingsPath = $_ENV['RECORDINGS_PATH'] ?? null;
        // No recordings are deleted when the bot is ready, unless a test asks for it.
        // env() also reads the process's own environment, which a shell can set.
        putenv('BOT_REMOVE_OLD_COMMANDS');
        unset($_ENV['BOT_SLASH_COMMANDS'], $_SERVER['BOT_SLASH_COMMANDS'], $_ENV['BOT_REMOVE_OLD_COMMANDS'], $_SERVER['BOT_REMOVE_OLD_COMMANDS'], $_ENV['RECORDINGS_RETENTION_DAYS'], $_SERVER['RECORDINGS_RETENTION_DAYS']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['BOT_SLASH_COMMANDS'], $_ENV['BOT_REMOVE_OLD_COMMANDS'], $_ENV['RECORDINGS_RETENTION_DAYS'], $_ENV['RECORDINGS_PATH']);

        if ($this->originalRecordingsPath !== null) {
            $_ENV['RECORDINGS_PATH'] = $this->originalRecordingsPath;
        }

        if ($this->recordings !== null) {
            exec('rm -rf ' . escapeshellarg($this->recordings));
        }

        foreach (array_reverse($this->appFiles) as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
    }

    public function testHandlesEachEventClassUnderItsEventName(): void
    {
        $app = $this->app();
        $message = $this->getMockBuilder(Message::class)->disableOriginalConstructor()->onlyMethods(['__get'])->getMock();

        // app/Events/MessageCreate.php handles MESSAGE_CREATE: it sees the message was sent in a server, and leaves it alone.
        $message->expects($this->once())->method('__get')->with('guild_id')->willReturn('100');

        $app->discord->emit(Event::MESSAGE_CREATE, [$message, $app->discord]);
    }

    public function testRefusesEventClassesNotNamedAfterADiscordEvent(): void
    {
        $this->addAppFile('Events/MessageCreated.php', "<?php\n\nnamespace App\\Events;\n\nfinal class MessageCreated\n{\n}\n");

        $this->expectException(EventNotFoundException::class);
        $this->expectExceptionMessage('Event MessageCreated not found');

        $this->app();
    }

    public function testRunsBeforeAndAfterAroundTheEvent(): void
    {
        $this->emitRecordingEvent();

        $this->assertSame(['before', 'first:hi', 'after'], RecordingEvent::$calls);
    }

    public function testBeforeCanStopTheEvent(): void
    {
        RecordingEvent::$before = true;

        $this->emitRecordingEvent();

        $this->assertSame(['before', 'after'], RecordingEvent::$calls);
    }

    public function testLogsErrorsInEventsAndStillRunsAfter(): void
    {
        RecordingEvent::$before = new RuntimeException('Something broke');

        $this->emitRecordingEvent();

        $this->assertSame(['before', 'after'], RecordingEvent::$calls);
        $this->assertContains('Error while handling event: Something broke', $this->logged());
    }

    public function testRunsTheReadyCallbackWhenTheBotIsReady(): void
    {
        $readyWith = null;
        $app = $this->app(function (Discord $discord) use (&$readyWith) {
            $readyWith = $discord;
        });

        $app->discord->emit('init', [$app->discord]);

        $this->assertSame($app->discord, $readyWith);
        $this->assertContains('Bot is ready!', $this->logged());
        $this->assertContains('Slash commands are disabled.', $this->logged());
    }

    public function testDeletesOldRecordingsWhenTheBotIsReadyThenEveryHour(): void
    {
        $old = $this->recordedCall('100', daysAgo: 31);
        $recent = $this->recordedCall('100', daysAgo: 29);
        $_ENV['RECORDINGS_RETENTION_DAYS'] = '30';
        $timers = [];
        $app = $this->app(loop: $this->loopCollectingTimers($timers));

        $app->discord->emit('init', [$app->discord]);

        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($recent);
        $this->assertContains(['Deleted old recordings', ['calls' => 1, 'days' => 30]], $this->loggedWithContext());

        // From then on it checks every hour, on the bot's event loop.
        $this->assertSame([3600], array_keys($timers));
        $oldByNow = $this->recordedCall('200', daysAgo: 31);
        ($timers[3600])();

        $this->assertDirectoryDoesNotExist($oldByNow);
        $this->assertDirectoryExists($recent);
    }

    public function testKeepsEveryRecordingWhenNoRetentionIsSet(): void
    {
        $old = $this->recordedCall('100', daysAgo: 4000);
        $timers = [];
        $app = $this->app(loop: $this->loopCollectingTimers($timers));

        $app->discord->emit('init', [$app->discord]);

        $this->assertDirectoryExists($old);
        $this->assertSame([], $timers);
        $this->assertNotContains('Deleted old recordings', $this->logged());
    }

    public function testDeletesTheCopiesOfWhatWasSaidThatCallsLeftInTheTempFolderWhenTheBotIsReady(): void
    {
        // The voice client's decoders copy each speaker's audio to the temp folder, named after when
        // they started and the speaker's SSRC. A call the bot didn't get to end, as when it crashed, leaves them there.
        $copies = [sys_get_temp_dir() . '/2026-10-04_21-07-666004.ogg', sys_get_temp_dir() . '/' . date('Y-m-d_H-i') . '-666005.ogg'];
        $others = [sys_get_temp_dir() . '/2026-10-04_21-07-666004.ogg.txt', sys_get_temp_dir() . '/2026-10-04_21-07-voice.ogg'];
        array_map(touch(...), [...$copies, ...$others]);
        $app = $this->app();

        $app->discord->emit('init', [$app->discord]);

        $this->assertSame([false, false], array_map(is_file(...), $copies));
        $this->assertSame([true, true], array_map(is_file(...), $others), 'Files named otherwise are not the voice client\'s.');
        array_map(unlink(...), $others);
    }

    public function testSavesCommandsDiscordDoesNotHaveYet(): void
    {
        [$app, $commands] = $this->appWithCommands();

        $app->prepareCommandClasses();

        $this->assertSame([
            'forget' => ['Deletes what the bot remembers about you, or about you and the people you have calls with.', Command::CHAT_INPUT],
            'meet' => ['Starts a private voice meeting with the people you pick, which I join and record.', Command::CHAT_INPUT],
            'memory' => ['Shows what the bot remembers about you, or about you and the people you have calls with.', Command::CHAT_INPUT],
            'optin' => ['Lets the bot record, transcribe and answer you again, after /optout.', Command::CHAT_INPUT],
            'optout' => ['Stops the bot from recording, transcribing or answering you, in every server.', Command::CHAT_INPUT],
            'privacy' => ['Shows or changes your privacy settings, such as when the bot may use your personal memory in calls.', Command::CHAT_INPUT],
            'recall' => ["Asks Claude a question about this server's saved calls.", Command::CHAT_INPUT],
            'record' => ['Records your voice channel and lets everyone in it talk to Claude.', Command::CHAT_INPUT],
            'settings' => ["Shows or changes this server's wake word, language, voice and Claude model.", Command::CHAT_INPUT],
            'share' => ['Lets the bot use your personal memory for everyone in the call it is recording.', Command::CHAT_INPUT],
            'stats' => ['Shows how this server has used the bot.', Command::CHAT_INPUT],
            'stop' => ['Stops recording and leaves the voice channel.', Command::CHAT_INPUT],
            'unshare' => ['Takes your personal memory back from the call it was shared with.', Command::CHAT_INPUT],
        ], $commands->saved);
        $this->assertContains('Global commands found: ForgetCommand, MeetCommand, MemoryCommand, OptinCommand, OptoutCommand, PrivacyCommand, RecallCommand, RecordCommand, SettingsCommand, ShareCommand, StatsCommand, StopCommand, UnshareCommand', $this->logged());
        $this->assertContains('Command record has been saved.', $this->logged());
        $this->assertSame(['forget', 'meet', 'memory', 'optin', 'optout', 'privacy', 'recall', 'record', 'settings', 'share', 'stats', 'stop', 'unshare'], array_keys($commands->listeners));
    }

    public function testHandsACommandsInteractionsToItsClassAndLogsThem(): void
    {
        $this->addAppFile('Commands/Global/PingCommand.php', "<?php\n\nnamespace App\\Commands\\Global;\n\nuse App\\CommandAbstract;\nuse Discord\\Parts\\Interactions\\Interaction;\n\nfinal class PingCommand extends CommandAbstract\n{\n    public string \$description = 'Answers with a pong';\n\n    public function handle(Interaction \$interaction): void\n    {\n        \$this->log->info('Pong!');\n    }\n}\n");
        [$app, $commands] = $this->appWithCommands();

        $app->prepareCommandClasses();

        $this->assertSame(['Answers with a pong', Command::CHAT_INPUT], $commands->saved['ping']);
        ($commands->listeners['ping'])(new Interaction($app->discord, ['guild_id' => '100', 'channel_id' => '200', 'user' => ['id' => '555', 'username' => 'alice']], true));
        $this->assertContains(['/ping used', ['guild' => '100', 'channel' => '200', 'user' => '555']], $this->loggedWithContext());
        $this->assertContains('Pong!', $this->logged());
    }

    public function testOnlyRegistersGlobalCommands(): void
    {
        // Commands in Commands/Guild are meant for one server, which isn't supported yet.
        $this->addAppFile('Commands/Guild/PingCommand.php', "<?php\n\nnamespace App\\Commands\\Guild;\n\nfinal class PingCommand\n{\n}\n");
        [$app, $commands] = $this->appWithCommands();

        $app->prepareCommandClasses();

        $this->assertContains('Guild specific commands found: PingCommand', $this->logged());
        $this->assertSame(['forget', 'meet', 'memory', 'optin', 'optout', 'privacy', 'recall', 'record', 'settings', 'share', 'stats', 'stop', 'unshare'], array_keys($commands->saved));
    }

    public function testDoesNotSaveCommandsDiscordAlreadyHas(): void
    {
        [$app, $commands] = $this->appWithCommands(registered: [
            ['name' => 'record', 'description' => 'Records your voice channel and lets everyone in it talk to Claude.', 'type' => Command::CHAT_INPUT],
            ['name' => 'stop', 'description' => 'Stops recording and leaves the voice channel.', 'type' => Command::CHAT_INPUT],
        ]);

        $app->prepareCommandClasses();

        $this->assertSame(['forget', 'meet', 'memory', 'optin', 'optout', 'privacy', 'recall', 'settings', 'share', 'stats', 'unshare'], array_keys($commands->saved));
        $this->assertContains('Command record already exists.', $this->logged());
        $this->assertContains('Command stop already exists.', $this->logged());
        $this->assertSame(['forget', 'meet', 'memory', 'optin', 'optout', 'privacy', 'recall', 'record', 'settings', 'share', 'stats', 'stop', 'unshare'], array_keys($commands->listeners), 'Existing commands are still handled.');
    }

    public function testSavesCommandsThatChanged(): void
    {
        [$app, $commands] = $this->appWithCommands(registered: [
            // /record as it was registered before its description changed.
            ['name' => 'record', 'description' => 'Starts recording the current voice channel.', 'type' => Command::CHAT_INPUT],
        ]);

        $app->prepareCommandClasses();

        $this->assertSame(['Records your voice channel and lets everyone in it talk to Claude.', Command::CHAT_INPUT], $commands->saved['record']);
        $this->assertNotContains('Command record already exists.', $this->logged());
    }

    public function testRegistersACommandsOptionsAndPermissions(): void
    {
        [$app, $commands] = $this->appWithCommands();

        $app->prepareCommandClasses();

        // /settings takes options, and is only shown to members with Manage Server (1 << 5).
        $settings = $commands->payloads['settings'];
        $this->assertSame(
            ['wake_word' => Option::STRING, 'language' => Option::STRING, 'voice' => Option::STRING, 'model' => Option::STRING, 'reset' => Option::BOOLEAN],
            array_column($settings['options'], 'type', 'name'),
        );
        $this->assertSame(
            [['name' => 'haiku', 'value' => 'haiku'], ['name' => 'sonnet', 'value' => 'sonnet'], ['name' => 'opus', 'value' => 'opus']],
            $settings['options'][3]['choices'],
        );
        $this->assertSame('32', $settings['default_member_permissions']);
        $this->assertSame(200, $settings['options'][0]['max_length'], 'Discord stops a wake word that is too long from being typed: five spellings of 32, with their commas.');

        // /memory and /forget take up to four other people, to pick the memory shared with them.
        foreach (['memory', 'forget'] as $command) {
            $this->assertSame(
                ['with' => Option::USER, 'with2' => Option::USER, 'with3' => Option::USER, 'with4' => Option::USER],
                array_column($commands->payloads[$command]['options'], 'type', 'name'),
            );
            $this->assertSame([], array_filter(array_column($commands->payloads[$command]['options'], 'required')), 'None of them is needed.');
        }

        // /privacy has one option, with the choices the setting has.
        $this->assertSame(
            [['type' => Option::STRING, 'name' => 'personal_memory_in_calls', 'description' => 'When I may use your personal memory in a call with other people.', 'choices' => [['name' => 'when I ask', 'value' => 'when_asked'], ['name' => 'only after /share', 'value' => 'after_share']]]],
            $commands->payloads['privacy']['options'],
        );
        $this->assertNull($commands->payloads['privacy']['default_member_permissions'], 'Anyone who can use slash commands can use it.');

        // /recall can't be used without its question.
        $this->assertSame(
            [['type' => Option::STRING, 'name' => 'question', 'description' => 'What you want to know, e.g. "what did we decide about the launch date?"', 'required' => true]],
            $commands->payloads['recall']['options'],
        );
        $this->assertNull($commands->payloads['recall']['default_member_permissions'], 'Anyone who can use slash commands can use it.');

        // /meet takes up to four people. Discord refuses a command whose required options don't come first.
        $this->assertSame(
            ['person' => Option::USER, 'person2' => Option::USER, 'person3' => Option::USER, 'person4' => Option::USER],
            array_column($commands->payloads['meet']['options'], 'type', 'name'),
        );
        $this->assertSame([true, null, null, null], array_map(fn (array $option) => $option['required'] ?? null, $commands->payloads['meet']['options']));
        $this->assertNull($commands->payloads['meet']['default_member_permissions'], 'Anyone who can use slash commands can use it.');

        // A command without them is sent as such, which also removes the ones Discord still has.
        $this->assertSame(
            ['name' => 'stop', 'description' => 'Stops recording and leaves the voice channel.', 'options' => [], 'default_member_permissions' => null, 'type' => Command::CHAT_INPUT],
            $commands->payloads['stop'],
        );
    }

    public function testEveryOptionFitsDiscordsLimits(): void
    {
        [$app, $commands] = $this->appWithCommands();

        $app->prepareCommandClasses();

        // Discord refuses the whole command otherwise, which only shows once the bot runs.
        foreach ($commands->payloads as $command => $payload) {
            $this->assertLessThanOrEqual(25, count($payload['options']), "/{$command} has too many options.");

            foreach ($payload['options'] as $option) {
                $this->assertMatchesRegularExpression('/^[-_\p{Ll}\p{N}]{1,32}$/u', $option['name'], "An option name of /{$command}.");
                $this->assertMatchesRegularExpression('/^.{1,100}$/u', $option['description'], "The description of /{$command} {$option['name']}.");
                $this->assertLessThanOrEqual(25, count($option['choices'] ?? []), "/{$command} {$option['name']} has too many choices.");
            }
        }
    }

    public function testDoesNotSaveACommandWhoseOptionsAndPermissionsDiscordAlreadyHas(): void
    {
        [$app, $commands] = $this->appWithCommands(registered: [self::registeredSettings()]);

        $app->prepareCommandClasses();

        $this->assertArrayNotHasKey('settings', $commands->saved);
        $this->assertContains('Command settings already exists.', $this->logged());
    }

    public function testDoesNotSaveACommandWhoseRequiredOptionDiscordAlreadyHas(): void
    {
        // /recall as Discord returns it once registered.
        $declared = (new ReflectionClass(RecallCommand::class))->getDefaultProperties();
        [$app, $commands] = $this->appWithCommands(registered: [[
            'id' => '903',
            'application_id' => '901',
            'version' => '904',
            'name' => 'recall',
            'description' => $declared['description'],
            'type' => Command::CHAT_INPUT,
            'options' => json_decode(json_encode([['name_localizations' => null, 'description_localizations' => null, ...array_reverse($declared['options'][0])]])),
            'default_member_permissions' => null,
        ]]);

        $app->prepareCommandClasses();

        $this->assertArrayNotHasKey('recall', $commands->saved);
        $this->assertContains('Command recall already exists.', $this->logged());
    }

    /**
     * @param array<string, mixed> $registered /settings as Discord has it.
     */
    #[DataProvider('outdatedSettings')]
    public function testSavesACommandAgainWhenItsOptionsOrPermissionsChanged(array $registered): void
    {
        [$app, $commands] = $this->appWithCommands(registered: [$registered]);

        $app->prepareCommandClasses();

        $this->assertArrayHasKey('settings', $commands->saved);
        $this->assertNotContains('Command settings already exists.', $this->logged());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function outdatedSettings(): iterable
    {
        $current = self::registeredSettings();
        $declared = json_decode(json_encode($current['options']), true);
        $with = fn (array $options) => [[...$current, 'options' => json_decode(json_encode($options))]];

        // The description and type are the same in each: only the options or the permissions differ.
        yield 'registered before it had options' => [array_diff_key($current, ['options' => true, 'default_member_permissions' => true])];
        yield 'an option was added since' => $with(array_slice($declared, 0, -1));
        yield 'an option was removed since' => $with([...$declared, ['type' => Option::BOOLEAN, 'name' => 'summaries', 'description' => 'Posts a summary of each call.']]);
        yield 'the options are in another order' => $with(array_reverse($declared));
        yield 'a description changed' => $with([['description' => 'The wake word.'] + $declared[0], ...array_slice($declared, 1)]);
        yield 'a limit changed' => $with([['max_length' => 64] + $declared[0], ...array_slice($declared, 1)]);
        yield 'a choice was added since' => $with([...array_slice($declared, 0, 3), ['choices' => array_slice($declared[3]['choices'], 0, 2)] + $declared[3], $declared[4]]);
        yield 'an option is no longer required' => $with([['required' => true] + $declared[0], ...array_slice($declared, 1)]);
        yield 'it was open to everyone' => [[...$current, 'default_member_permissions' => null]];
        yield 'it needed another permission' => [[...$current, 'default_member_permissions' => '8']];
    }

    public function testLogsWhenTheRegisteredCommandsCannotBeFetched(): void
    {
        [$app, $commands] = $this->appWithCommands(fetchError: new RuntimeException('Discord API unavailable'));

        $app->prepareCommandClasses();

        $this->assertSame([], $commands->saved);
        $this->assertContains('Could not fetch the registered commands: Discord API unavailable', $this->logged());
        $this->assertSame(['forget', 'meet', 'memory', 'optin', 'optout', 'privacy', 'recall', 'record', 'settings', 'share', 'stats', 'stop', 'unshare'], array_keys($commands->listeners), 'Commands Discord already has keep working.');
    }

    public function testLogsCommandsThatCannotBeSaved(): void
    {
        [$app] = $this->appWithCommands(saveError: new RuntimeException('Invalid Form Body'));

        $app->prepareCommandClasses();

        $this->assertContains('Could not save command record: Invalid Form Body', $this->logged());
        $this->assertNotContains('Command record has been saved.', $this->logged());
    }

    public function testNamesTheCommandsDiscordHasAndTheBotDoesNotAndLeavesThem(): void
    {
        [$app, $commands] = $this->appWithCommands(registered: self::leftovers());

        $app->prepareCommandClasses();

        $this->assertSame([], $commands->removed, 'Nothing is removed unless BOT_REMOVE_OLD_COMMANDS is set.');
        $this->assertContains('Discord has global commands the bot has no class for: join, leave, live. Set BOT_REMOVE_OLD_COMMANDS to remove them.', $this->logged());
        $this->assertTrue($this->logs->hasWarning('Discord has global commands the bot has no class for: join, leave, live. Set BOT_REMOVE_OLD_COMMANDS to remove them.'));
    }

    #[DataProvider('switchesThatRemove')]
    public function testRemovesTheCommandsDiscordHasAndTheBotDoesNotWhenToldTo(string $value): void
    {
        $_ENV['BOT_REMOVE_OLD_COMMANDS'] = $value;
        // /record is one of the bot's own, saved in the same start because its description changed.
        [$app, $commands] = $this->appWithCommands(registered: [
            ...self::leftovers(),
            ['id' => '4', 'name' => 'record', 'description' => 'Starts recording the current voice channel.', 'type' => Command::CHAT_INPUT],
        ]);

        $app->prepareCommandClasses();

        $this->assertSame(['join' => '1', 'leave' => '2', 'live' => '3'], $commands->removed, 'Only what the bot has no class for, each by its id.');
        $this->assertContains('Discord has global commands the bot has no class for: join, leave, live.', $this->logged());
        $this->assertTrue($this->logs->hasInfo('Command join has been removed.'));
        $this->assertTrue($this->logs->hasInfo('Command leave has been removed.'));
        $this->assertTrue($this->logs->hasInfo('Command live has been removed.'));
        $this->assertTrue($this->logs->hasWarning('Discord has global commands the bot has no class for: join, leave, live.'));
        $this->assertContains('record', array_keys($commands->saved), 'The bot still saves its own.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function switchesThatRemove(): iterable
    {
        yield 'true' => ['true'];
        yield '1' => ['1'];
        yield 'yes' => ['yes'];
        yield 'on' => ['on'];
    }

    #[DataProvider('switchesThatDoNotRemove')]
    public function testDoesNotRemoveWhenTheSwitchIsOffOrNotAnAnswer(string $value): void
    {
        $_ENV['BOT_REMOVE_OLD_COMMANDS'] = $value;
        [$app, $commands] = $this->appWithCommands(registered: self::leftovers());

        $app->prepareCommandClasses();

        $this->assertSame([], $commands->removed);
        $this->assertContains('Discord has global commands the bot has no class for: join, leave, live. Set BOT_REMOVE_OLD_COMMANDS to remove them.', $this->logged());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function switchesThatDoNotRemove(): iterable
    {
        yield 'empty' => [''];
        yield 'false' => ['false'];
        yield '0' => ['0'];
        yield 'off' => ['off'];
        yield 'no' => ['no'];
        yield 'anything else' => ['maybe'];
    }

    public function testSaysNothingWhenDiscordHasNoCommandTheBotLacks(): void
    {
        $_ENV['BOT_REMOVE_OLD_COMMANDS'] = 'true';
        [$app, $commands] = $this->appWithCommands(registered: [
            ['id' => '4', 'name' => 'record', 'description' => 'Records your voice channel and lets everyone in it talk to Claude.', 'type' => Command::CHAT_INPUT],
        ]);

        $app->prepareCommandClasses();

        $this->assertSame([], $commands->removed);
        $this->assertSame([], preg_grep('/^Discord has global commands/', $this->logged()));
    }

    public function testTellsCommandsApartByNameAndType(): void
    {
        $_ENV['BOT_REMOVE_OLD_COMMANDS'] = 'true';
        // Discord allows /record as a slash command and as a user command: only the slash command is the bot's.
        [$app, $commands] = $this->appWithCommands(registered: [
            ['id' => '4', 'name' => 'record', 'description' => 'Records your voice channel and lets everyone in it talk to Claude.', 'type' => Command::CHAT_INPUT],
            ['id' => '5', 'name' => 'record', 'description' => '', 'type' => Command::USER],
            ['id' => '6', 'name' => 'join', 'description' => '', 'type' => Command::MESSAGE],
            ['id' => '7', 'name' => 'join', 'description' => 'Joins your channel.', 'type' => Command::CHAT_INPUT],
        ]);

        $app->prepareCommandClasses();

        $this->assertSame(['join' => '7', 'join (type 3)' => '6', 'record (type 2)' => '5'], $commands->removed);
        $this->assertTrue($this->logs->hasWarning('Discord has global commands the bot has no class for: join, join (type 3), record (type 2).'));
        $this->assertTrue($this->logs->hasInfo('Command record (type 2) has been removed.'));
    }

    public function testKeepsTheBotsOwnCommandsOfOtherTypesAndThoseWithoutAType(): void
    {
        $_ENV['BOT_REMOVE_OLD_COMMANDS'] = 'true';
        // A class that makes a user command, as CommandAbstract::$type allows.
        $this->addAppFile('Commands/Global/WaveCommand.php', "<?php\n\nnamespace App\Commands\Global;\n\nuse App\CommandAbstract;\nuse Discord\Parts\Interactions\Interaction;\n\nfinal class WaveCommand extends CommandAbstract\n{\n    public string \$description = 'Waves.';\n\n    public ?int \$type = 2;\n\n    public function handle(Interaction \$interaction): void\n    {\n    }\n}\n");
        [$app, $commands] = $this->appWithCommands(registered: [
            ['id' => '4', 'name' => 'wave', 'description' => '', 'type' => Command::USER],
            // Discord always says the type; one that is missing is a slash command, as it is when the bot makes one.
            ['id' => '5', 'name' => 'stop', 'description' => 'Stops recording and leaves the voice channel.'],
            ['id' => '6', 'name' => 'wave', 'description' => 'Waves.', 'type' => Command::CHAT_INPUT],
        ]);

        $app->prepareCommandClasses();

        $this->assertSame(['wave' => '6'], $commands->removed, 'The slash command named like the bot\'s user command is a leftover, the others are the bot\'s own.');
    }

    public function testSkipsACommandTheCacheLost(): void
    {
        $_ENV['BOT_REMOVE_OLD_COMMANDS'] = 'true';
        // DiscordPHP's repository iterates over the commands it still holds: one whose weak reference is gone is null.
        [$app, $commands] = $this->appWithCommands(registered: [null, ...self::leftovers()]);

        $app->prepareCommandClasses();

        $this->assertSame(['join' => '1', 'leave' => '2', 'live' => '3'], $commands->removed);
    }

    public function testLogsACommandThatCannotBeRemovedAndGoesOn(): void
    {
        $_ENV['BOT_REMOVE_OLD_COMMANDS'] = 'true';
        [$app, $commands] = $this->appWithCommands(registered: self::leftovers(), removeErrors: ['join' => new RuntimeException('Missing Access')]);

        $app->prepareCommandClasses();

        $this->assertTrue($this->logs->hasError('Could not remove command join: Missing Access'));
        $this->assertNotContains('Command join has been removed.', $this->logged());
        $this->assertSame(['leave' => '2', 'live' => '3'], $commands->removed, 'The others are still removed.');
        $this->assertContains('Command record has been saved.', $this->logged(), 'And the bot still saves its own.');
    }

    public function testRemovesNothingWhenTheRegisteredCommandsCannotBeFetched(): void
    {
        $_ENV['BOT_REMOVE_OLD_COMMANDS'] = 'true';
        [$app, $commands] = $this->appWithCommands(registered: self::leftovers(), fetchError: new RuntimeException('Discord API unavailable'));

        $app->prepareCommandClasses();

        $this->assertSame([], $commands->removed);
        $this->assertSame([], preg_grep('/^Discord has global commands/', $this->logged()));
    }

    public function testRemovesNothingWhenSlashCommandsAreDisabled(): void
    {
        $_ENV['BOT_REMOVE_OLD_COMMANDS'] = 'true';
        [$app, $commands] = $this->appWithCommands(registered: self::leftovers());
        unset($_ENV['BOT_SLASH_COMMANDS']);

        $app->prepareCommandClasses();

        $this->assertSame([], $commands->removed);
        $this->assertSame([], $commands->saved);
        $this->assertContains('Slash commands are disabled.', $this->logged());
    }

    public function testClosesTheBotWhenCommandsCannotBeRegistered(): void
    {
        $_ENV['BOT_SLASH_COMMANDS'] = 'true';
        $app = $this->app();
        $client = $app->discord;
        $listeners = [];
        $app->discord = $this->discordStub($client, ['application' => new RuntimeException('Discord API unavailable')], $listeners, expectClose: true);

        $client->emit('init', [$client]);

        $this->assertContains('Error while preparing command classes: Discord API unavailable', $this->logged());
    }

    public function testRunStartsTheBot(): void
    {
        $app = $this->app();
        $app->discord = $this->getMockBuilder(Discord::class)->disableOriginalConstructor()->onlyMethods(['run'])->getMock();
        $app->discord->expects($this->once())->method('run');

        $app->run();
    }

    /**
     * An application whose Discord client never connects: it runs on a loop that is never started,
     * so its requests to Discord are queued and never sent.
     */
    private function app(?Closure $ready = null, ?LoopInterface $loop = null): Application
    {
        return new Application(
            ['token' => 'test-token', 'loop' => $loop ?? new StreamSelectLoop(), 'logger' => new Logger('test', [$this->logs])],
            $ready,
        );
    }

    /**
     * An event loop that never runs.
     *
     * @param array<int, callable> &$timers Collects what the bot asks it to repeat, by the seconds in between.
     */
    private function loopCollectingTimers(array &$timers): LoopInterface
    {
        $loop = static::createStub(LoopInterface::class);
        $loop->method('addPeriodicTimer')->willReturnCallback(function (int|float $interval, callable $callback) use (&$timers) {
            $timers[$interval] = $callback;
        });

        return $loop;
    }

    /**
     * Makes the folder of a call that started the given number of days ago, where the bot keeps its recordings.
     *
     * @return string The folder's path.
     */
    private function recordedCall(string $guildId, int $daysAgo): string
    {
        $this->recordings ??= sys_get_temp_dir() . '/application-test-' . uniqid();
        $_ENV['RECORDINGS_PATH'] = $this->recordings;
        $call = sprintf('%s/%s/%s', $this->recordings, $guildId, date('Y-m-d_H-i-s', time() - $daysAgo * 86400));
        mkdir($call, 0755, true);
        touch("{$call}/transcript.txt");

        return $call;
    }

    /**
     * An application with slash commands enabled, whose Discord application already has the given commands.
     *
     * @param list<array<string, mixed>|null> $registered Attributes of the commands Discord already has; null for one the cache lost, which DiscordPHP also yields.
     * @param array<string, \Throwable> $removeErrors Why removing the command of that name fails.
     * @return array{Application, object} The application, and the command repository that records what is saved and removed.
     */
    private function appWithCommands(array $registered = [], ?\Throwable $fetchError = null, ?\Throwable $saveError = null, array $removeErrors = []): array
    {
        $_ENV['BOT_SLASH_COMMANDS'] = 'true';
        $app = $this->app();
        $client = $app->discord;
        $registered = array_map(fn (?array $attributes) => $attributes === null ? null : new Command($client, $attributes, true), $registered);

        // Behaves like DiscordPHP's GlobalCommandRepository: freshen() fetches the registered commands.
        $commands = new class ($registered, $fetchError, $saveError, $removeErrors) implements \IteratorAggregate {
            /** @var array<string, array{string, int}> Saved commands: name => [description, type]. */
            public array $saved = [];

            /** @var array<string, array<string, mixed>> What was sent to Discord to save each command, by name. */
            public array $payloads = [];

            /** @var array<string, callable> Interaction handlers by command name. */
            public array $listeners = [];

            /** @var array<string, string> The commands removed from Discord: name => the ID that was sent. */
            public array $removed = [];

            /**
             * @param list<Command|null> $registered
             * @param array<string, \Throwable> $removeErrors
             */
            public function __construct(
                private array $registered,
                private ?\Throwable $fetchError,
                private ?\Throwable $saveError,
                private array $removeErrors,
            ) {
            }

            public function getIterator(): \Traversable
            {
                return new \ArrayIterator($this->registered);
            }

            public function delete(Command $command): PromiseInterface
            {
                if (isset($this->removeErrors[$command->name])) {
                    return reject($this->removeErrors[$command->name]);
                }

                $this->removed[$command->name . ($command->type === Command::CHAT_INPUT ? "" : " (type {$command->type})")] = $command->id;

                return resolve($command);
            }

            public function freshen(): PromiseInterface
            {
                return $this->fetchError === null ? resolve($this) : reject($this->fetchError);
            }

            public function find(callable $callback): ?Command
            {
                foreach ($this->registered as $command) {
                    if ($command !== null && $callback($command)) {
                        return $command;
                    }
                }

                return null;
            }

            public function save(Command $command): PromiseInterface
            {
                if ($this->saveError !== null) {
                    return reject($this->saveError);
                }

                $this->saved[$command->name] = [$command->description, $command->type];
                // What DiscordPHP's repository posts for a command it didn't get from Discord.
                $this->payloads[$command->name] = $command->getCreatableAttributes();

                return resolve($command);
            }
        };
        $app->discord = $this->discordStub($client, ['application' => (object) ['commands' => $commands]], $commands->listeners);

        return [$app, $commands];
    }

    /**
     * Commands Discord has that no class of the bot makes, as an earlier project registered them: listed out of order.
     *
     * @return list<array<string, mixed>>
     */
    private static function leftovers(): array
    {
        return [
            ['id' => '3', 'name' => 'live', 'description' => 'Starts a live.', 'type' => Command::CHAT_INPUT],
            ['id' => '1', 'name' => 'join', 'description' => 'Joins your channel.', 'type' => Command::CHAT_INPUT],
            ['id' => '2', 'name' => 'leave', 'description' => 'Leaves your channel.', 'type' => Command::CHAT_INPUT],
        ];
    }

    /**
     * /settings as Discord returns it once registered, which is not how it was sent: the options
     * are objects with their fields in Discord's order, and they also have what wasn't sent, as
     * null or false, and what the bot never sets.
     *
     * @return array<string, mixed>
     */
    private static function registeredSettings(): array
    {
        $declared = (new ReflectionClass(SettingsCommand::class))->getDefaultProperties();
        $options = array_map(
            fn (array $option) => [
                'name_localizations' => null,
                'description_localizations' => null,
                'name_localized' => $option['name'],
                'required' => false,
                'autocomplete' => false,
                'min_length' => null,
                'min_value' => null,
                'max_value' => null,
                'channel_types' => null,
                'choices' => null,
                ...array_reverse($option),
                ...(isset($option['choices']) ? ['choices' => array_map(fn (array $choice) => ['name_localizations' => null, ...array_reverse($choice)], $option['choices'])] : []),
            ],
            $declared['options'],
        );

        return [
            'id' => '900',
            'application_id' => '901',
            'version' => '902',
            'name' => 'settings',
            'name_localizations' => null,
            'description' => $declared['description'],
            'description_localizations' => null,
            'type' => Command::CHAT_INPUT,
            'options' => json_decode(json_encode($options)),
            'default_member_permissions' => (string) $declared['defaultMemberPermissions'],
            'dm_permission' => true,
            'contexts' => null,
            'integration_types' => [0],
            'nsfw' => false,
        ];
    }

    /**
     * Adds a file to one of the app's folders until the test ends: Application finds events and commands there.
     */
    private function addAppFile(string $path, string $contents): void
    {
        $path = dirname(__DIR__, 2) . "/app/{$path}";

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path));
            $this->appFiles[] = dirname($path);
        }

        file_put_contents($path, $contents);
        $this->appFiles[] = $path;
    }

    private function emitRecordingEvent(): void
    {
        $app = $this->app();
        $app->handleEvent('TEST_EVENT', RecordingEvent::class);

        $app->discord->emit('TEST_EVENT', [(object) ['content' => 'hi'], $app->discord]);
    }

    /**
     * A Discord client with the given properties; a property that is an exception is thrown when read.
     *
     * @param array<string, mixed>     $properties
     * @param array<string, callable> &$listeners  Collects the handlers passed to listenCommand().
     */
    private function discordStub(Discord $client, array $properties, array &$listeners, bool $expectClose = false): Discord
    {
        $methods = ['__get', 'getLogger', 'getHttpClient', 'getFactory', 'listenCommand', 'close'];
        $discord = $expectClose
            ? $this->getMockBuilder(Discord::class)->disableOriginalConstructor()->onlyMethods($methods)->getMock()
            : static::getStubBuilder(Discord::class)->disableOriginalConstructor()->onlyMethods($methods)->getStub();

        if ($expectClose) {
            $discord->expects($this->once())->method('close');
        }

        $discord->method('__get')->willReturnCallback(function (string $name) use ($properties) {
            $value = $properties[$name] ?? null;

            return $value instanceof \Throwable ? throw $value : $value;
        });
        $discord->method('getLogger')->willReturn($client->getLogger());
        $discord->method('getHttpClient')->willReturn($client->getHttpClient());
        $discord->method('getFactory')->willReturn($client->getFactory());
        $discord->method('listenCommand')->willReturnCallback(function (string $name, callable $callback) use (&$listeners): RegisteredCommand {
            $listeners[$name] = $callback;

            return (new ReflectionClass(RegisteredCommand::class))->newInstanceWithoutConstructor();
        });

        return $discord;
    }

    /**
     * @return list<string>
     */
    private function logged(): array
    {
        return array_map(fn ($record) => $record->message, $this->logs->getRecords());
    }

    /**
     * @return list<array{string, array<string, mixed>}>
     */
    private function loggedWithContext(): array
    {
        return array_map(fn ($record) => [$record->message, $record->context], $this->logs->getRecords());
    }
}
