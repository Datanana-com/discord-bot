<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Fails the test run when a test leaves a socket or a timer in ReactPHP's event loop.
 *
 * ReactPHP runs the loop when PHP shuts down, and a loop with a listening socket in it never ends: the tests
 * print "OK" and then `composer test` hangs, and CI waits for its timeout without saying why. When the tests
 * are over this raises a PHPUnit warning (a PHPUnit warning fails the run by default) that lists
 * what is still waiting, with the test that left it when there is one, and takes it out of the loop so that
 * the run ends instead of hanging.
 *
 * Set in phpunit.xml as an extension.
 */
final class EventLoopCheck implements Extension
{
    /** @var array<string, string> What was waiting in the loop before the test that is running, and before its setUp(). */
    private array $before = [];

    /** @var array<string, string> Key of a thing in the loop => the test that left it there. */
    private array $leftBy = [];

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(
            new class ($this) implements PreparationStartedSubscriber {
                public function __construct(private readonly EventLoopCheck $check)
                {
                }

                public function notify(PreparationStarted $event): void
                {
                    $this->check->testStarted();
                }
            },
            new class ($this) implements FinishedSubscriber {
                public function __construct(private readonly EventLoopCheck $check)
                {
                }

                public function notify(Finished $event): void
                {
                    $this->check->testFinished($event->test()->id());
                }
            },
            new class ($this) implements ExecutionFinishedSubscriber {
                public function __construct(private readonly EventLoopCheck $check)
                {
                }

                public function notify(ExecutionFinished $event): void
                {
                    $this->check->executionFinished();
                }
            },
        );
    }

    public function testStarted(): void
    {
        $this->before = EventLoopInspector::waiting();
    }

    public function testFinished(string $test): void
    {
        $waiting = EventLoopInspector::waiting();
        // The key of what is gone can come back for something else: object ids are reused.
        $this->leftBy = array_intersect_key($this->leftBy, $waiting);

        foreach (array_diff_key($waiting, $this->before) as $key => $what) {
            $this->leftBy[$key] ??= $test;
        }
    }

    public function executionFinished(): void
    {
        EventLoopInspector::settle();
        $waiting = EventLoopInspector::waiting();

        if ($waiting === []) {
            return;
        }

        $lines = [];

        foreach ($waiting as $key => $what) {
            $lines[] = '- ' . $what . (isset($this->leftBy[$key]) ? ", left by {$this->leftBy[$key]}" : '');
        }

        // Without this the run ends with a hang: ReactPHP runs the loop at shutdown, and it has something to wait for.
        EventLoopInspector::clear();

        EventFacade::emitter()->testRunnerTriggeredPhpunitWarning(
            "The tests left something waiting in the event loop, which would keep PHP from ending (and CI from finishing):\n" . implode("\n", $lines),
        );
    }
}
