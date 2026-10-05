<?php

declare(strict_types=1);

namespace Tests\Unit\Logs;

use App\Logs\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    // The logger writes to the real stdout, so it runs in its own process to keep the test output clean.
    #[RunInSeparateProcess]
    public function testLogsEverythingToStdoutAndAsJsonToTodaysLogFile(): void
    {
        $directory = sys_get_temp_dir() . '/logger-test-' . uniqid();
        mkdir($directory);
        chdir($directory);

        $logger = new Logger();
        $logger->info('Hello from the test', ['guild' => '100']);

        $this->assertSame('DiscordPHP', $logger->getName());
        $this->assertSame(
            [['php://stdout', Level::Debug], [$directory . '/logs/' . date('Y-m-d') . '.log', Level::Debug]],
            array_map(fn (StreamHandler $handler) => [$handler->getUrl(), $handler->getLevel()], $logger->getHandlers()),
        );
        $this->assertInstanceOf(RotatingFileHandler::class, $logger->getHandlers()[1], 'The next day starts a new file, also while the bot runs.');

        $lines = array_map(
            fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file('logs/' . date('Y-m-d') . '.log', FILE_IGNORE_NEW_LINES),
        );
        $this->assertSame(['Logger initialized', 'Hello from the test'], array_column($lines, 'message'));
        $this->assertSame(['DEBUG', 'INFO'], array_column($lines, 'level_name'));
        $this->assertSame(['guild' => '100'], $lines[1]['context']);

        exec('rm -rf ' . escapeshellarg($directory));
    }
}
