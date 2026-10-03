<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application;
use App\Exceptions\EventNotFoundException;
use Closure;
use Discord\Discord;
use Discord\Parts\Application\Command\Command;
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Interaction;
use Discord\Helpers\RegisteredCommand;
use Discord\WebSockets\Event;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
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

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
        RecordingEvent::$calls = [];
        RecordingEvent::$before = null;
        unset($_ENV['BOT_SLASH_COMMANDS'], $_SERVER['BOT_SLASH_COMMANDS']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['BOT_SLASH_COMMANDS']);

        foreach (array_reverse($this->appFiles) as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
    }

    public function testHandlesEachEventClassUnderItsEventName(): void
    {
        $app = $this->app();
        $message = (new ReflectionClass(Message::class))->newInstanceWithoutConstructor();

        // app/Events/MessageCreate.php handles MESSAGE_CREATE.
        $app->discord->emit(Event::MESSAGE_CREATE, [$message, $app->discord]);

        $this->assertContains('another example', $this->logged());
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

    public function testSavesCommandsDiscordDoesNotHaveYet(): void
    {
        [$app, $commands] = $this->appWithCommands();

        $app->prepareCommandClasses();

        $this->assertSame([
            'record' => ['Records your voice channel and lets everyone in it talk to Claude.', Command::CHAT_INPUT],
            'stop' => ['Stops recording and leaves the voice channel.', Command::CHAT_INPUT],
            'test' => ['A test global command', Command::CHAT_INPUT],
        ], $commands->saved);
        $this->assertContains('Global commands found: RecordCommand, StopCommand, TestCommand', $this->logged());
        $this->assertContains('Command record has been saved.', $this->logged());

        // Each command's interactions go to its class: /test logs a greeting.
        $this->assertSame(['record', 'stop', 'test'], array_keys($commands->listeners));
        ($commands->listeners['test'])((new ReflectionClass(Interaction::class))->newInstanceWithoutConstructor());
        $this->assertContains('Hello, World!', $this->logged());
    }

    public function testOnlyRegistersGlobalCommands(): void
    {
        // Commands in Commands/Guild are meant for one server, which isn't supported yet.
        $this->addAppFile('Commands/Guild/PingCommand.php', "<?php\n\nnamespace App\\Commands\\Guild;\n\nfinal class PingCommand\n{\n}\n");
        [$app, $commands] = $this->appWithCommands();

        $app->prepareCommandClasses();

        $this->assertContains('Guild specific commands found: PingCommand', $this->logged());
        $this->assertSame(['record', 'stop', 'test'], array_keys($commands->saved));
    }

    public function testDoesNotSaveCommandsDiscordAlreadyHas(): void
    {
        [$app, $commands] = $this->appWithCommands(registered: [
            ['name' => 'record', 'description' => 'Records your voice channel and lets everyone in it talk to Claude.', 'type' => Command::CHAT_INPUT],
            ['name' => 'stop', 'description' => 'Stops recording and leaves the voice channel.', 'type' => Command::CHAT_INPUT],
        ]);

        $app->prepareCommandClasses();

        $this->assertSame(['test'], array_keys($commands->saved));
        $this->assertContains('Command record already exists.', $this->logged());
        $this->assertContains('Command stop already exists.', $this->logged());
        $this->assertSame(['record', 'stop', 'test'], array_keys($commands->listeners), 'Existing commands are still handled.');
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

    public function testLogsWhenTheRegisteredCommandsCannotBeFetched(): void
    {
        [$app, $commands] = $this->appWithCommands(fetchError: new RuntimeException('Discord API unavailable'));

        $app->prepareCommandClasses();

        $this->assertSame([], $commands->saved);
        $this->assertContains('Could not fetch the registered commands: Discord API unavailable', $this->logged());
        $this->assertSame(['record', 'stop', 'test'], array_keys($commands->listeners), 'Commands Discord already has keep working.');
    }

    public function testLogsCommandsThatCannotBeSaved(): void
    {
        [$app] = $this->appWithCommands(saveError: new RuntimeException('Invalid Form Body'));

        $app->prepareCommandClasses();

        $this->assertContains('Could not save command record: Invalid Form Body', $this->logged());
        $this->assertNotContains('Command record has been saved.', $this->logged());
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
    private function app(?Closure $ready = null): Application
    {
        return new Application(
            ['token' => 'test-token', 'loop' => new StreamSelectLoop(), 'logger' => new Logger('test', [$this->logs])],
            $ready,
        );
    }

    /**
     * An application with slash commands enabled, whose Discord application already has the given commands.
     *
     * @param list<array<string, mixed>> $registered Attributes of the commands Discord already has.
     * @return array{Application, object} The application, and the command repository that records what is saved.
     */
    private function appWithCommands(array $registered = [], ?\Throwable $fetchError = null, ?\Throwable $saveError = null): array
    {
        $_ENV['BOT_SLASH_COMMANDS'] = 'true';
        $app = $this->app();
        $client = $app->discord;
        $registered = array_map(fn (array $attributes) => new Command($client, $attributes, true), $registered);

        // Behaves like DiscordPHP's GlobalCommandRepository: freshen() fetches the registered commands.
        $commands = new class ($registered, $fetchError, $saveError) {
            /** @var array<string, array{string, int}> Saved commands: name => [description, type]. */
            public array $saved = [];

            /** @var array<string, callable> Interaction handlers by command name. */
            public array $listeners = [];

            /** @param list<Command> $registered */
            public function __construct(
                private array $registered,
                private ?\Throwable $fetchError,
                private ?\Throwable $saveError,
            ) {
            }

            public function freshen(): PromiseInterface
            {
                return $this->fetchError === null ? resolve($this) : reject($this->fetchError);
            }

            public function find(callable $callback): ?Command
            {
                foreach ($this->registered as $command) {
                    if ($callback($command)) {
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

                return resolve($command);
            }
        };
        $app->discord = $this->discordStub($client, ['application' => (object) ['commands' => $commands]], $commands->listeners);

        return [$app, $commands];
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
}
