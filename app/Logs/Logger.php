<?php

declare(strict_types=1);

namespace App\Logs;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Psr\Log\LoggerInterface;
use Monolog\Logger as Monolog;

final class Logger extends Monolog implements LoggerInterface
{
    /**
     * @inheritDoc
     */
    public function __construct()
    {
        // A file per day, logs/<Y-m-d>.log, also when the bot runs for days. None is ever deleted.
        $file = new RotatingFileHandler('logs/bot.log', 0, Level::Debug);
        $file->setFilenameFormat('{date}', RotatingFileHandler::FILE_PER_DAY);
        // One JSON object per line, so the log file can be searched and summed up, e.g. with jq.
        $file->setFormatter(new JsonFormatter());

        parent::__construct(
            'DiscordPHP',
            [
                new StreamHandler('php://stdout', Level::Debug),
                $file,
            ]
        );

        $this->debug('Logger initialized');
    }
}
