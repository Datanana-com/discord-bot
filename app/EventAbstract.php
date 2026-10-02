<?php

declare(strict_types=1);

namespace App;

use Exception;
use App\Exceptions\EventFunctionNotFoundException;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\Channel\Reaction;
use Psr\Log\LoggerInterface;

/**
 * @property Message $message
 * @property Discord $discord
 * @property Reaction $messageReaction
 */
abstract class EventAbstract
{
    /**
     * The event's logger
     *
     * @var LoggerInterface
     */
    protected LoggerInterface $log;

    /**
     * Initializes the EventAbstract's data
     *
     * @param object|Message|Reaction $eventData The event object to handle data from
     * @param Discord $discord The Discord client
     * @param array $executableMethods The list of methods to execute
     */
    public function __construct(
        public object $eventData,
        public Discord $discord,
        private array $executableMethods = [],
    ) {
        $this->log = $discord->getLogger();
    }

    /**
     * Executes the event's methods.
     *
     * @param string $name
     * @return object|Message|Reaction|mixed
     */
    public function __get(string $name)
    {
        if (!property_exists($this, $name)) {
            // The property passed to the class normally is from the Event's object
            // e.g. MessageCreate returns a Message object, so we pass it to the $this->eventData
            // property to get the object's properties, if it doesn't exist already
            return $this->eventData;
        }

        return $this->{$name};
    }

    /**
     * Retrieves the class' methods that are executable by the event.
     *
     * @return array
     */
    public function getExecutableMethods(): array
    {
        return $this->executableMethods;
    }

    /**
     * Handles the user defined validations before the event's functions.
     * When returned true, the event's functions will not be executed.
     *
     * @return bool|void|null
     */
    public function before()
    {
    }

    /**
     * Handles the user defined validations after the event's functions.
     *
     * @return void
     */
    public function after(): void
    {
    }

    /**
     * Handles the order of the events.
     * The return value of the function will determine if the event should be terminated.
     * 1. If the function returns `true` or `throws an exception`, the event **will** be terminated.
     * 2. If the function returns `false` or `null` or `void`, the event **will not** be terminated.
     *
     * @param EventAbstract|\Discord\WebSockets\Event $class The event object to handle data from
     * @param Discord $discord The Discord object to handle data from
     * @return bool|void
     */
    public function handle($class = null)
    {
        if ($class !== null && !isset($this->eventData)) {
            $this->eventData = $class;
        }

        if ($this->executableMethods === []) {
            $this->log->warning('No executable methods were found for this event.');
            return false;
        }

        foreach ($this->executableMethods as $method) {
            if (!method_exists($this, $method)) {
                throw new EventFunctionNotFoundException($method);
            }

            try {
                if ($this->{$method}($this->eventData, $this->discord) === true) {
                    // If the method returns true, the event loop is terminated.
                    // And log its success.
                    $this->log->info("Event \"{$method}\" was executed successfully.");
                    return true;
                }
            } catch (Exception $e) {
                $this->log->error("Event \"{$method}\" failed with the following error: {$e->getMessage()}");
                $this->log->error($e->getTraceAsString());
                return false;
            }
        }
    }

}
