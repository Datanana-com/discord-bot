<?php

declare(strict_types=1);

require_once 'bootstrap.php';

use App\Application;
use App\Logs\Failures;
use App\Logs\Logger;
use Discord\WebSockets\Intents;

/**
 * @see https://discord.com/developers/docs/intro
 */

$logger = new Logger();
// What nothing catches is written to the bot's log before PHP ends, not only to the terminal.
Failures::register($logger);

$app = new Application([
    'token' => env('DISCORD_TOKEN'),
    'intents' => Intents::getDefaultIntents() | Intents::GUILD_MEMBERS,
    'loadAllMembers' => true,
    'logger' => $logger,
]);

// Until it is stopped: with Ctrl+C or a signal, it first leaves its calls.
exit($app->run());
