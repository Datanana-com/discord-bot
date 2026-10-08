<?php

declare(strict_types=1);

namespace App;

use Closure;
use Throwable;
use App\Logs\Logger;
use Discord\Discord;
use ReflectionClass;
use App\Logs\Failures;
use App\Voice\Meeting;
use App\Voice\Retention;
use App\Analytics\Usage;
use BadMethodCallException;
use React\EventLoop\Loop;
use App\Voice\VoiceSession;
use App\Support\Shell;
use Illuminate\Support\Str;
use App\Support\GuardedLoop;
use Psr\Log\LoggerInterface;
use Discord\WebSockets\Event;
use React\Promise\PromiseInterface;
use Discord\Builders\MessageBuilder;
use App\Exceptions\EventNotFoundException;
use Discord\Parts\Application\Command\Command;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Interactions\Request\Option as RequestOption;
use App\Commands\SuggestsOptions;

use function React\Promise\all;

final class Application
{
    /** The signals the bot is stopped with: Ctrl+C in its terminal, and what `kill` and a service manager send. */
    private const array SIGNALS = [2 => 'SIGINT', 15 => 'SIGTERM'];

    /**
     * This many exceptions caught in the event loop within {@see FLOOD_SECONDS} are no longer something
     * that failed once: whatever throws them does it every time it runs, and the bot stops.
     */
    private const int FLOOD = 10;

    private const float FLOOD_SECONDS = 10.0;

    /** Seconds the event loop still runs once the bot has closed its connection to Discord: what it wrote there is only sent while the loop runs. */
    private const float LAST_WORDS = 0.5;

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

    /** The event loop the bot runs on, which catches what its callbacks throw. */
    private GuardedLoop $loop;

    /** @var list<float> When the last exceptions were caught in the event loop. */
    private array $caughtAt = [];

    /** Whether the bot is stopping over too many of them. */
    private bool $flooded = false;

    private bool $stopping = false;

    private bool $closed = false;

    /** What the process ends with. */
    private int $exitCode = 0;

    /**
     * Initializes the Application
     *
     * @param array $options
     */
    public function __construct(array $options, ?Closure $readyFunction = null)
    {
        // Only create the default logger when none is given: it opens a log file.
        $options['logger'] ??= new Logger();
        // What a callback of the event loop throws is caught there: thrown on, it would end the bot, still in its calls.
        $this->loop = $options['loop'] = new GuardedLoop($options['loop'] ?? Loop::get(), $this->caught(...));
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

                // Deletes old recordings now and every hour, when RECORDINGS_RETENTION_DAYS is set.
                Retention::fromEnv($discord->getLogger())?->start($discord->getLoop());
                // And what calls the bot didn't get to end, as when it crashed, left in the temp folder.
                VoiceSession::deleteDecoderFiles();

                try {
                    $this->prepareCommandClasses();
                } catch (\Throwable $th) {
                    $discord->getLogger()->error('Error while preparing command classes: ' . $th->getMessage(), Failures::context($th));
                    // Not 0: the bot didn't just stop.
                    $this->exitCode = 1;
                    $this->discord->close();
                }

            }
        );
    }

    /**
     * Starts the ReactPHP event loop, and stops the bot when the process is told to end.
     *
     * @return int What the process ends with: 0, or 1 when the bot stopped over errors.
     */
    public function run(): int
    {
        // Also for what uses the static Loop, like the programs the bot runs.
        Loop::set($this->loop);
        // Once the loop is between two callbacks. ReactPHP has PHP handle a signal the moment it arrives, in the
        // middle of whatever is running: a call that is handing on the audio it was just sent would be stopped
        // underneath itself.
        $stop = function (int $signal): void {
            $this->loop->futureTick(fn () => $this->stop('received ' . self::SIGNALS[$signal]));
        };

        try {
            foreach (array_keys(self::SIGNALS) as $signal) {
                $this->loop->addSignal($signal, $stop);
            }
        } catch (BadMethodCallException) {
            // The event loop can only be told about signals with the pcntl extension.
            $this->log->warning('The pcntl extension is not loaded: stopped with Ctrl+C, the bot ends without leaving its calls, and the programs it runs go on without it.');
        }

        try {
            $this->discord->run();
        } finally {
            foreach (array_keys(self::SIGNALS) as $signal) {
                $this->loop->removeSignal($signal, $stop);
            }

            // The statistics still held are written however the bot ends: when the calls were summarized, when it was
            // told to stop twice, and when it failed to start. Nobody waits for the bot any more.
            (new Usage($this->log))->flush();

            // What is still running ends with the bot, like a summary it no longer waited for: in a session of
            // their own, the programs would go on without it.
            Shell::stopAll();
        }

        return $this->exitCode;
    }

    /**
     * Stops the bot. Every call is stopped as /stop does it, so the bot leaves its voice channels at once,
     * and the meetings /meet made are ended, which deletes their channels. The bot then goes on until every
     * call is summarized and remembered, however long that takes, and ends.
     *
     * Told to stop again, it no longer waits for that.
     *
     * @param string $reason Why, for the log.
     * @param int $exitCode What the process ends with. Not 0 when the bot stops over errors: the text
     *                      channel of each call is then told that it had to leave.
     */
    public function stop(string $reason, int $exitCode = 0): void
    {
        $this->exitCode = max($this->exitCode, $exitCode);

        if ($this->stopping) {
            $this->log->warning('Stopping now, without waiting for the calls', ['reason' => $reason]);
            $this->close();

            return;
        }

        $this->stopping = true;
        // None that would start while the ones there are are summarized: nothing would stop it.
        VoiceSession::refuseNewCalls();
        $calls = VoiceSession::unfinished();
        $this->log->info('Stopping the bot', ['reason' => $reason, 'calls' => count($calls)]);

        // Neither rejects.
        all([
            ...array_map(fn (VoiceSession $call) => $exitCode === 0 ? $call->stop() : $call->abandon(), $calls),
            Meeting::endAll(),
        ])->then($this->close(...));
    }

    /**
     * Closes the connection to Discord, and ends the event loop.
     */
    private function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        // A normal close, after which Discord shows the bot as offline, and no longer in any voice channel.
        // The loop is not stopped with it: the close is only sent while the loop runs.
        $this->discord->close(false);
        $this->loop->addTimer(self::LAST_WORDS, fn () => $this->loop->stop());
    }

    /**
     * What a callback of the event loop threw is logged, and the bot goes on. Unless it keeps happening:
     * the bot then leaves its calls and stops, and whatever runs the bot can start it again.
     */
    private function caught(Throwable $e): void
    {
        // Each one has been logged often enough by now.
        if ($this->flooded) {
            return;
        }

        $this->log->error('Something failed in the event loop: ' . $e->getMessage(), Failures::context($e));
        $now = microtime(true);
        $this->caughtAt = [...array_filter($this->caughtAt, fn (float $at) => $now - $at < self::FLOOD_SECONDS), $now];

        if (count($this->caughtAt) >= self::FLOOD) {
            $this->flooded = true;
            $this->log->critical('Too much is failing in the event loop: leaving every call and stopping', ['failures' => count($this->caughtAt), 'seconds' => self::FLOOD_SECONDS]);
            $this->stop('too many errors', 1);
        }
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
            function ($event, Discord $discord) use ($eventName, $eventClass, $childMethodsToRun) {
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
                } catch (Throwable $e) {
                    $logger->error('Error while handling event: ' . $e->getMessage(), ['event' => $eventName, ...Failures::context($e)]);
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

                try {
                    $working = $commandClass->handle($interaction);
                } catch (Throwable $e) {
                    $this->commandFailed($commandName, $interaction, $e);

                    return;
                }

                $working?->catch(fn (Throwable $e) => $this->commandFailed($commandName, $interaction, $e));
            }, $commandClass instanceof SuggestsOptions
                ? fn (Interaction $interaction, ?RequestOption $focused) => $this->suggest($commandName, $commandClass, $interaction, $focused)
                : null);
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

                $this->handleLeftoverCommands($registered, array_map(self::describe(...), array_values($commands)));
            },
            fn (\Throwable $e) => $this->log->error('Could not fetch the registered commands: ' . $e->getMessage()),
        );
    }

    /**
     * What a command offers for the option someone is typing. Nobody is told when it fails and nothing is
     * logged for each keystroke, only for the failure: the option can still be typed without suggestions.
     *
     * @return list<array{name: string, value: string}>
     */
    private function suggest(string $commandName, SuggestsOptions $command, Interaction $interaction, ?RequestOption $focused): array
    {
        try {
            return $command->suggest($interaction, $focused);
        } catch (Throwable $e) {
            $this->log->warning("/{$commandName} could not suggest: {$e->getMessage()}", [
                'guild' => $interaction->guild_id,
                'user' => $interaction->user?->id,
                ...Failures::context($e),
            ]);

            return [];
        }
    }

    /**
     * Logs that a command failed, and tells whoever used it: Discord would otherwise tell them that the
     * application did not respond, or leave what the command had replied so far. Only they see it, unless
     * the command had already answered in the channel, as /record and /meet do: that answer is changed.
     *
     * They aren't told what failed: an error nobody expected can hold paths, and other things nobody in a
     * server needs.
     */
    private function commandFailed(string $commandName, Interaction $interaction, Throwable $e): void
    {
        $this->log->error("/{$commandName} failed: {$e->getMessage()}", [
            'guild' => $interaction->guild_id,
            'channel' => $interaction->channel_id,
            'user' => $interaction->user?->id,
            ...Failures::context($e),
        ]);
        $reply = MessageBuilder::new()->setContent("Something went wrong with /{$commandName}. The bot's logs say what.");

        $this->tell($interaction, $reply)->catch(
            fn (Throwable $e) => $this->log->warning("Could not tell that /{$commandName} failed: {$e->getMessage()}", ['guild' => $interaction->guild_id]),
        );
    }

    /**
     * Replies to a command, or changes what it replied when it already has: Discord takes one reply.
     */
    private function tell(Interaction $interaction, MessageBuilder $reply): PromiseInterface
    {
        return $interaction->isResponded()
            ? $interaction->updateOriginalResponse($reply)
            : $interaction->respondWithMessage($reply, ephemeral: true);
    }

    /**
     * Names the global commands Discord has and the bot has no class for, and removes them when BOT_REMOVE_OLD_COMMANDS is set.
     *
     * Only the bot's own checkout knows what its commands are: two checkouts with different commands under one
     * Discord application would remove each other's, so nothing is removed unless asked for.
     *
     * @param iterable<Command> $registered The commands Discord has.
     * @param list<string> $known How the commands the bot has a class for are described: Discord tells commands apart by name and type.
     */
    private function handleLeftoverCommands(iterable $registered, array $known): void
    {
        $leftovers = [];

        foreach ($registered as $command) {
            if ($command !== null && ! in_array(self::describe($command), $known, true)) {
                $leftovers[] = $command;
            }
        }

        if ($leftovers === []) {
            return;
        }

        usort($leftovers, fn (Command $a, Command $b) => self::describe($a) <=> self::describe($b));
        $names = implode(', ', array_map(self::describe(...), $leftovers));
        $remove = filter_var(env('BOT_REMOVE_OLD_COMMANDS', false), FILTER_VALIDATE_BOOLEAN);

        $this->log->warning("Discord has global commands the bot has no class for: {$names}." . ($remove ? '' : ' Set BOT_REMOVE_OLD_COMMANDS to remove them.'));

        if (! $remove) {
            return;
        }

        foreach ($leftovers as $command) {
            $this->discord->application->commands->delete($command)->then(
                fn () => $this->log->info("Command " . self::describe($command) . " has been removed."),
                fn (\Throwable $e) => $this->log->error("Could not remove command " . self::describe($command) . ": {$e->getMessage()}"),
            );
        }
    }

    /**
     * A command by its name, and its type when it is not a slash command: Discord allows the same name for each type.
     */
    private static function describe(Command $command): string
    {
        $type = $command->type ?? Command::CHAT_INPUT;

        return $type === Command::CHAT_INPUT ? $command->name : "{$command->name} (type {$type})";
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
