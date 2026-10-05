<?php

declare(strict_types=1);

namespace App;

use Discord\Discord;
use Discord\Parts\Interactions\Interaction;
use Psr\Log\LoggerInterface;

abstract class CommandAbstract
{
    /**
     * Guild ID in which to create the command.
     *
     * @var string|null
     */
    public ?string $guildId = null;

    /**
     * Description of the command.
     *
     * @var string
     */
    public string $description;

    public ?int $type = null;

    /**
     * The command's options, each as Discord describes an option: its type, name, description, and so on.
     *
     * @see https://docs.discord.com/developers/interactions/application-commands#application-command-object-application-command-option-structure
     *
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    /**
     * The permissions a member needs to be shown the command, as a bit set. Null shows it to everyone.
     *
     * Server admins can change who is shown a command, so a command that needs a permission
     * also checks it in handle().
     *
     * @var int|null
     */
    public ?int $defaultMemberPermissions = null;

    /**
     * The event's logger
     *
     * @var LoggerInterface
     */
    protected LoggerInterface $log;

    public function __construct(
        public Discord $discord,
    ) {
        $this->log = $discord->getLogger();

        $this->log->info('Command initialized: ' . static::class);
    }

    abstract public function handle(Interaction $interaction): void;
}
