<?php

declare(strict_types=1);

namespace App\Commands;

use App\CommandAbstract;
use Discord\Parts\Interactions\Interaction;

final class TestGlobalCommand extends CommandAbstract
{
    public string $description = 'A test global command';

    public function handle(Interaction $interaction): void
    {
        $this->log->info('Hello, World!');
    }
}
