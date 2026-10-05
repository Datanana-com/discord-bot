<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Event\Facade as EventFacade;
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
 * Set in phpunit.xml as an extension. Not for the Live suite: that one talks to real Discord, whose client leaves timers in the loop,
 * which tests/Live/VoiceRoundTripTest.php deals with by stopping the loop. Needs ReactPHP's default loop, the one that is used when
 * the ev, event and uv extensions are not installed (see {@see EventLoopInspector}).
 */
final class EventLoopCheck implements Extension
{
    /** @var array<string, string> What was waiting in the loop when the running test started preparing, before its setUp(). */
    private array $before = [];

    /** @var string|null The test that is running, or the last one that ran. */
    private ?string $current = null;

    /** @var array<string, string> Key of a thing in the loop => the test that left it there. */
    private array $leftBy = [];

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (in_array('Live', $configuration->includeTestSuites(), true)) {
            return;
        }

        $facade->registerSubscribers(
            new class ($this) implements PreparationStartedSubscriber {
                public function __construct(private readonly EventLoopCheck $check)
                {
                }

                public function notify(PreparationStarted $event): void
                {
                    $this->check->testStarted($event->test()->id());
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

    /**
     * A test is starting, so the one before it is over, whether it passed, failed, or was skipped in its setUp().
     */
    public function testStarted(string $test): void
    {
        $this->blameTheCurrentTest();
        $this->current = $test;
        $this->before = EventLoopInspector::waiting();
    }

    public function executionFinished(): void
    {
        $this->blameTheCurrentTest();
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

    /**
     * What is in the loop now, and was not when the current test started, is what that test left.
     */
    private function blameTheCurrentTest(): void
    {
        if ($this->current === null) {
            return;
        }

        $waiting = EventLoopInspector::waiting();
        // The key of what is gone can come back for something else: object ids are reused.
        $this->leftBy = array_intersect_key($this->leftBy, $waiting);

        foreach (array_diff_key($waiting, $this->before) as $key => $what) {
            $this->leftBy[$key] ??= $this->current;
        }
    }
}
