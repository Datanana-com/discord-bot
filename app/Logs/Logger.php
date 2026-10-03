<?php

declare(strict_types=1);

namespace App\Logs;

use Carbon\Carbon;
use Monolog\Formatter\JsonFormatter;
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
        $filename = Carbon::now()->format('Y-m-d');

        // One JSON object per line, so the log file can be searched and summed up, e.g. with jq.
        $file = new StreamHandler("logs/$filename.log", Level::Debug);
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
