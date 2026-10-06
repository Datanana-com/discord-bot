<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\CommandAbstract;
use Discord\Parts\Interactions\Interaction;
use React\Promise\PromiseInterface;

final class TestCommand extends CommandAbstract
{
    public string $description = 'A test global command';

    public function handle(Interaction $interaction): ?PromiseInterface
    {
        $this->log->info('Hello, World!');

        return null;
    }
}
