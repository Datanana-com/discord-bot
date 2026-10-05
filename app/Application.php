<?php

declare(strict_types=1);

namespace App;

use Closure;
use App\Logs\Logger;
use Discord\Discord;
use ReflectionClass;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Discord\WebSockets\Event;
use App\Exceptions\EventNotFoundException;
use Discord\Parts\Application\Command\Command;
use Discord\Parts\Interactions\Interaction;

class Application
{
    /**
     * @var \Discord\Discord
     */
    public Discord $discord;

    /**
     * Logger instance.
     *
     * @var \Psr\Log\LoggerInterface
     */
    public LoggerInterface $log;

    /**
     * Allowed events from Discord instance.
     *
     * @var array
     */
    private array $allowedEvents = [];

    /**
     * Initializes the Application
     *
     * @param array $options
     */
    public function __construct(array $options, ?Closure $readyFunction = null)
    {
        // Only create the default logger when none is given: it opens a log file.
        $options['logger'] ??= new Logger();
        $this->discord = new Discord($options);
        $this->log = $this->discord->getLogger();

        // Retrieves every event name from the constants from the \Discord\WebSockets\Event class
        $this->allowedEvents = (new ReflectionClass(Event::class))->getConstants();

        $this->prepareEventClasses();

        // Handles the "on ready" bot event.
        $this->discord->on(
            'init',
            function (Discord $discord) use ($readyFunction) {
                $discord->getLogger()->info('Bot is ready!');

                if ($readyFunction !== null) {
                    $readyFunction($discord);
                }

                try {
                    $this->prepareCommandClasses();
                } catch (\Throwable $th) {
                    $discord->getLogger()->error('Error while preparing command classes: ' . $th->getMessage());
                    $discord->getLogger()->error('Error while preparing command classes: ' . $th->getTraceAsString());
                    $this->discord->close();
                }

            }
        );
    }

    /**
     * Starts the ReactPHP event loop.
     */
    public function run(): void
    {
        $this->discord->run();
    }

    /**
     * Retrieves the list of classes in a folder.
     *
     * @param string $folder
     * @return array
     */
    private function getClassesFromFolder(string $folder): array
    {
        return array_map(
            fn (string $path) => basename($path, '.php'),
            glob(__DIR__ . "/{$folder}/*.php") ?: [],
        );
    }

    /**
     * Prepares the event classes.
     *
     * @return void
     */
    public function prepareEventClasses(): void
    {
        $events = $this->getClassesFromFolder('Events');

        foreach ($events as $event) {
            // Transforms the event class to the event name
            // e.g. MessageCreate => MESSAGE_CREATE
            $eventClass = $event;
            $eventName = strtoupper(snake($event));

            if (!in_array($eventName, $this->allowedEvents)) {
                throw new EventNotFoundException($event);
            }

            $this->handleEvent($eventName, "\\App\\Events\\$eventClass");
        }
    }

    /**
     * Handles a events.
     *
     * @param string $eventName
     * @param string $eventClass
     * @return void
     */
    public function handleEvent(string $eventName, string $eventClass): void
    {
        $parentClass = get_parent_class($eventClass);
        $parentMethods = [];
        if ($parentClass) {
            $parentMethods = get_class_methods($parentClass);
        }

        $childMethods = get_class_methods($eventClass);

        // Retrieves the childs methods by removing the parent methods
        $childMethodsToRun = array_diff($childMethods, $parentMethods);

        // Removes special methods
        $childMethodsToRun = array_filter(
            $childMethodsToRun,
            fn ($method) => str_contains($method, '__') === false
        );

        /**
         * @var \App\EventAbstract $eventClass
         */
        $this->discord->on(
            $eventName,
            function ($event, Discord $discord) use ($eventClass, $childMethodsToRun) {
                $eventHandlerClass = new $eventClass($event, $discord, $childMethodsToRun);
                $logger = $discord->getLogger();

                try {
                    // Executes the event's methods before the event handler
                    $logger->debug('Executing the event handler before the event class..');
                    if ($eventHandlerClass->before()) {
                        return true;
                    }

                    // Handles all of the functions within the event's class
                    $logger->debug('Executing the event handler..');
                    $eventHandlerClass->handle();
                } catch (\Exception $e) {
                    $logger->error('Error while handling event: ' . $e->getMessage());
                    $logger->error('Trace' . $e->getTraceAsString());
                    return false;
                } finally {
                    // Executes the event's methods after the event handler
                    // To "fake" a middleware
                    $logger->debug('Executing the event handler after the event class..');
                    $eventHandlerClass->after();
                }
            }
        );
    }

    /**
     * Register the bot's slash commands
     *
     * @return void
     */
    public function prepareCommandClasses(): void
    {
        if (env('BOT_SLASH_COMMANDS', false) === false) {
            $this->log->info('Slash commands are disabled.');
            return;
        }

        // A command's folder says where it is registered: in every server, or in one.
        $globalCommands = $this->getClassesFromFolder('Commands/Global');
        $guildSpecificCommands = $this->getClassesFromFolder('Commands/Guild');

        if (!empty($globalCommands)) {
            $this->log->info('Global commands found: ' . implode(', ', $globalCommands));

            $this->handleGlobalCommands($globalCommands);
        }

        if (!empty($guildSpecificCommands)) {
            $this->log->info('Guild specific commands found: ' . implode(', ', $guildSpecificCommands));

            $this->handleGuildSpecificCommands($guildSpecificCommands);
        }
    }

    /**
     * Automates the handling of global commands by class.
     *
     * Commands are only saved to Discord when it doesn't have them yet or they changed,
     * as Discord limits how many commands can be created per day.
     *
     * @param array $globalCommandsClasses
     * @return void
     */
    public function handleGlobalCommands(array $globalCommandsClasses)
    {
        $commands = [];

        foreach ($globalCommandsClasses as $commandClass) {
            $commandName = Str::slug(strtolower(Str::replaceEnd('Command', '', $commandClass)));

            $commandClass = "\\App\\Commands\\Global\\{$commandClass}";
            $commandClass = new $commandClass($this->discord);
            $command = (new Command($this->discord))
                ->setName($commandName)
                ->setDescription($commandClass->description)
                ->setType($commandClass?->type ?? Command::CHAT_INPUT);
            // Set as they are sent: Command::addOption() loses the option. Both are always sent,
            // so that saving a command also removes the ones it no longer has.
            $command->options = $commandClass->options;
            $command->default_member_permissions = $commandClass->defaultMemberPermissions === null
                ? null
                : (string) $commandClass->defaultMemberPermissions;
            $commands[$commandName] = $command;

            $this->discord->listenCommand($commandName, function (Interaction $interaction) use ($commandName, $commandClass) {
                $this->log->info("/{$commandName} used", [
                    'guild' => $interaction->guild_id,
                    'channel' => $interaction->channel_id,
                    'user' => $interaction->user?->id,
                ]);
                $commandClass->handle($interaction);
            });
        }

        // The repository is empty until the registered commands are fetched from Discord.
        $this->discord->application->commands->freshen()->then(
            function ($registered) use ($commands) {
                foreach ($commands as $commandName => $command) {
                    $existing = $registered->find(fn (Command $registeredCommand) => $registeredCommand->name === $commandName);

                    if ($existing !== null && ! self::changed($existing, $command)) {
                        $this->log->info("Command {$commandName} already exists.");

                        continue;
                    }

                    // Saving a command under an existing name replaces it.
                    $registered->save($command)->then(
                        fn () => $this->log->info("Command {$commandName} has been saved."),
                        fn (\Throwable $e) => $this->log->error("Could not save command {$commandName}: {$e->getMessage()}"),
                    );
                }
            },
            fn (\Throwable $e) => $this->log->error('Could not fetch the registered commands: ' . $e->getMessage()),
        );
    }

    /**
     * Whether a command is no longer what Discord has registered for it, in what the bot sets.
     */
    private static function changed(Command $registered, Command $command): bool
    {
        return $registered->description !== $command->description
            || $registered->type !== $command->type
            || $registered->default_member_permissions !== $command->default_member_permissions
            || self::comparable($registered) != self::comparable($command);
    }

    /**
     * A command's options, reduced to what the bot sets on them.
     *
     * Discord returns an option with more than it was sent: null for what was left out, and nothing
     * for what is false. Comparing the options as they are would save every command on every start.
     * Localizations are not compared: Discord only returns them when asked to.
     *
     * @return list<array<string, mixed>>
     */
    private static function comparable(Command $command): array
    {
        // The raw attributes: reading $command->options turns them into parts, which are then sent as such.
        $options = json_decode(json_encode($command->getRawAttributes()['options'] ?? []), true);

        return array_map(self::comparableOption(...), $options);
    }

    /**
     * @param array<string, mixed> $option
     * @return array<string, mixed>
     */
    private static function comparableOption(array $option): array
    {
        $fields = ['type', 'name', 'description', 'required', 'choices', 'options', 'channel_types', 'min_value', 'max_value', 'min_length', 'max_length', 'autocomplete'];
        $option = array_intersect_key($option, array_flip($fields));
        $option['choices'] = array_map(fn (array $choice) => [$choice['name'], $choice['value']], $option['choices'] ?? []);
        $option['options'] = array_map(self::comparableOption(...), $option['options'] ?? []);

        return array_filter($option, fn (mixed $value) => ! in_array($value, [null, false, []], true));
    }

    /**
     * TODO: Add a way to handle guild specific commands
     *
     * @param string $commandClass
     * @return void
     */
    public function handleGuildSpecificCommands(array $commandClass)
    {

    }

}
