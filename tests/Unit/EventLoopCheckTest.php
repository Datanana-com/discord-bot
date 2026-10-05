<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CommandFailedException;
use App\Support\Shell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function React\Async\await;

/**
 * Runs PHPUnit on the tests of tests/Fixtures/EventLoopCheck, which leave a socket and a timer in the event loop,
 * to see what Tests\EventLoopCheck makes of them.
 */
final class EventLoopCheckTest extends TestCase
{
    public function testFailsTheRunAndNamesWhatATestLeftInTheEventLoop(): void
    {
        $output = $this->runPhpunit('Leaky', expectFailure: true);

        $this->assertStringContainsString('The tests left something waiting in the event loop', $output);
        // The socket of a server that was never closed, by its address, and the test that opened it.
        $this->assertMatchesRegularExpression(
            '/^- reading stream tcp_socket\S* local 127\.0\.0\.1:\d+, left by Tests\\\\Fixtures\\\\EventLoopCheck\\\\LeakyTest::testLeavesAListeningSocket$/m',
            $output,
        );
        // The timer, by where its callback is.
        $this->assertMatchesRegularExpression(
            '/^- timer once after 3600s, callback LeakyTest\.php:\d+, left by Tests\\\\Fixtures\\\\EventLoopCheck\\\\LeakyTest::testLeavesATimer$/m',
            $output,
        );
        // A server started in setUp(), as on #11, is blamed on the test that needed it as well.
        $this->assertMatchesRegularExpression(
            '/^- reading stream tcp_socket\S* local 127\.0\.0\.1:\d+, left by Tests\\\\Fixtures\\\\EventLoopCheck\\\\LeakyInSetUpTest::testNeedsAServer$/m',
            $output,
        );
        $this->assertStringNotContainsString('testLeavesNothing', $output, 'A test that closed what it opened is not blamed.');
        $this->assertStringContainsString('PHPUnit Warnings: 1', $output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cleanTests(): iterable
    {
        yield 'closes what it opened' => ['testClosesWhatItOpened'];
        // await() suspends the loop in the middle of closing the program's last stream, and of a timer's callback:
        // when the test ends there, they are still in the loop, though finished.
        yield 'ends after awaiting a program' => ['testEndsAfterAwaitingAProgram'];
        yield 'ends after waiting' => ['testEndsAfterWaiting'];
    }

    #[DataProvider('cleanTests')]
    public function testPassesTestsThatLeaveNothingWaiting(string $test): void
    {
        $output = $this->runPhpunit('Clean', expectFailure: false, filter: $test);

        $this->assertStringContainsString('OK (1 test, 1 assertion)', $output);
        $this->assertStringNotContainsString('event loop', $output);
    }

    /**
     * Runs one suite of the fixtures in a PHP of its own, and gives what PHPUnit printed. Without the check the run
     * with the leaks never ends: it is cut after 60 seconds, which fails the test.
     */
    private function runPhpunit(string $suite, bool $expectFailure, string $filter = '.'): string
    {
        $root = dirname(__DIR__, 2);
        $command = [PHP_BINARY, "{$root}/vendor/phpunit/phpunit/phpunit", '--configuration', "{$root}/tests/Fixtures/EventLoopCheck/phpunit.xml", '--testsuite', $suite, '--filter', $filter, '--do-not-record-test-run-history'];

        try {
            $output = await(Shell::run($command, cwd: $root, timeout: 60.0));
        } catch (CommandFailedException $e) {
            $this->assertTrue($expectFailure, "PHPUnit failed: {$e->getMessage()}");
            $this->assertStringContainsString(' exited with code 1', $e->getMessage(), 'The run did not end by itself, with something left in the loop.');

            return $e->stdout;
        }

        $this->assertFalse($expectFailure, 'PHPUnit should have failed.');

        return $output;
    }
}
