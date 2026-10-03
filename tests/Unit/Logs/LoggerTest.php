<?php

declare(strict_types=1);

namespace Tests\Unit\Logs;

use App\Logs\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    // The logger writes to the real stdout, so it runs in its own process to keep the test output clean.
    #[RunInSeparateProcess]
    public function testLogsEverythingToStdoutAndToTodaysLogFile(): void
    {
        $directory = sys_get_temp_dir() . '/logger-test-' . uniqid();
        mkdir($directory);
        chdir($directory);

        $logger = new Logger();
        $logger->info('Hello from the test');

        $this->assertSame('DiscordPHP', $logger->getName());
        $this->assertSame(
            [['php://stdout', Level::Debug], [$directory . '/logs/' . date('Y-m-d') . '.log', Level::Debug]],
            array_map(fn (StreamHandler $handler) => [$handler->getUrl(), $handler->getLevel()], $logger->getHandlers()),
        );

        $file = file_get_contents('logs/' . date('Y-m-d') . '.log');
        $this->assertStringContainsString('DiscordPHP.DEBUG: Logger initialized', $file);
        $this->assertStringContainsString('DiscordPHP.INFO: Hello from the test', $file);

        exec('rm -rf ' . escapeshellarg($directory));
    }
}
