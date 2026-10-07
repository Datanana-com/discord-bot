<?php

// Stands in for index.php, for a bot that ends over what nothing caught: it sets the log up like index.php
// does, and then fails the way its first argument says. Its logs go to the folder it is run from.

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Logs\Failures;
use App\Logs\Logger;
use React\EventLoop\Loop;

use function React\Promise\reject;

/**
 * Like the functions of a call, it is given what someone said, which a stack trace as PHP prints it would show.
 */
function hears(string $said): never
{
    throw new RuntimeException('Something broke');
}

Failures::register(new Logger());

if ($argv[1] === 'exception') {
    // Like the bot, which has started to connect to Discord before its loop runs. ReactPHP runs a loop that
    // never ran when PHP ends, unless PHP ends over a fatal error.
    Loop::addTimer(0.2, function () {
        echo "The event loop ran\n";
    });
}

match ($argv[1]) {
    'exception' => hears('what Alice said in the call'),
    // More memory than PHP may use, which no code can catch.
    'fatal' => ini_set('memory_limit', '32M') && str_repeat('x', 256 * 1024 * 1024),
    // Nothing holds the promise, so nothing will ever handle it.
    'rejection' => reject(new RuntimeException('Nobody handled this')),
};

echo "The bot goes on\n";
