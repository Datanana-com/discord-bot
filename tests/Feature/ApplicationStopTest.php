<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application;
use App\Voice\VoiceSession;
use Closure;
use Discord\Discord;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use ReflectionProperty;
use RuntimeException;
use Tests\Fixtures\GatedLoop;

use function React\Async\await;

/**
 * The bot as index.php runs it, with calls in progress, until it ends: stopped with a signal, which the
 * test's own process is sent, or over what fails in its event loop.
 */
final class ApplicationStopTest extends VoiceTestCase
{
    /** The event loop the tests run on: the bot makes its own the one everything uses. */
    private LoopInterface $loop;

    /** @var list<array{bool, int}> Each time the bot closed its connection to Discord: whether that was to stop the loop, and how many calls were summarized by then. */
    private array $closed = [];

    /** When it last did. */
    private float $closedAt = 0.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loop = Loop::get();
    }

    protected function tearDown(): void
    {
        Loop::set($this->loop);
        parent::tearDown();
    }

    #[DataProvider('signals')]
    public function testLeavesEveryCallAndEndsOnceTheyAreSummarizedWhenItIsToldToStop(int $signal, string $name): void
    {
        // Two calls, in two servers. Their voice clients expect to be closed exactly once.
        $first = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);
        $second = VoiceSession::start($this->voiceClient($other = $this->voiceChannel('400', '300'), connected: true), $other, $this->discord);
        $this->ask($vc, '555', 'Claude, what time is it?');
        $posted = count($this->sent);
        $this->assertFalse(VoiceSession::refusesNewCalls());

        $code = $this->runBot(fn () => Loop::addTimer(0.1, fn () => posix_kill(getmypid(), $signal)));

        $this->assertSame(0, $code, 'It was told to stop: nothing failed.');
        $this->assertTrue(VoiceSession::refusesNewCalls(), 'No call starts while it waits for the ones there were: nothing would stop it.');
        $this->assertSame(['received ' . $name], array_column($this->logged('Stopping the bot'), 'reason'));
        $this->assertGreaterThanOrEqual(2, $this->logged('Stopping the bot')[0]['calls']);
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertNull(VoiceSession::forGuild('300'));
        $this->assertCount(2, array_intersect([$first->id, $second->id], array_column($this->logged('Voice session stopped'), 'session')));

        // It only ended once the call something was said in was summarized, and both were over.
        $this->assertCount($posted + 1, $this->sent, 'The summary was posted.');
        $this->assertNotContains($first, VoiceSession::unfinished());
        $this->assertNotContains($second, VoiceSession::unfinished());
        // A normal close, which doesn't stop the loop: the close is only sent while the loop runs.
        $this->assertSame([[false, 1]], $this->closed);
        $this->assertGreaterThanOrEqual(0.4, microtime(true) - $this->closedAt);
        $this->assertSame([], $this->loggedProblems());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function signals(): iterable
    {
        yield 'Ctrl+C in its terminal' => [SIGINT, 'SIGINT'];
        yield 'kill, or a service manager' => [SIGTERM, 'SIGTERM'];
    }

    public function testNoLongerWaitsForItsCallsWhenItIsToldToStopAgain(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);
        $this->ask($vc, '555', 'Claude, what time is it?');
        // Claude takes its time with the summary.
        $this->setProcessEnv(['FAKE_CLAUDE_PAUSE' => '10']);
        $started = microtime(true);

        $code = $this->runBot(function () {
            Loop::addTimer(0.1, fn () => posix_kill(getmypid(), SIGINT));
            Loop::addTimer(0.5, fn () => posix_kill(getmypid(), SIGINT));
            // And once more while it is already ending.
            Loop::addTimer(0.7, fn () => posix_kill(getmypid(), SIGINT));
        });

        $this->assertSame(0, $code);
        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertSame(['Stopping now, without waiting for the calls', 'Stopping now, without waiting for the calls'], $this->loggedProblems());
        $this->assertSame([], $this->logged('Summarized the call'));
        // It had left the call the first time, and closes its connection to Discord once.
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame([[false, 0]], $this->closed);
        $this->assertContains($session, VoiceSession::unfinished());

        // The Claude Code that was writing the summary is ended with the bot: it would go on without it.
        $calls = $this->claudeCalls();
        $this->assertFalse(end($calls)['waited'], 'It was started for the summary.');
        $this->waitUntil(fn () => $this->hasEnded(end($calls)['pid']), 'Claude Code to end', 3.0);

        // Its stand-in has a part that still holds its output open.
        touch($this->claudeResume);
        await($session->stop());
        $this->assertStringStartsWith("Sorry, I couldn't summarize the call. (", end($this->sent));
        $this->assertStringContainsString('fake-claude was killed by signal 15', end($this->sent));
    }

    public function testGoesOnAfterWhatACallbackOfTheLoopThrew(): void
    {
        VoiceSession::start($this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);
        $app = $this->app();
        // Nine things had failed, long enough ago.
        (new ReflectionProperty(Application::class, 'caughtAt'))->setValue($app, array_fill(0, 9, microtime(true) - 11));
        $later = false;

        $code = $this->runBot(function () use (&$later) {
            // One fewer than is too much. Like the callbacks of a call, they are given what someone said.
            for ($failure = 1; $failure <= 9; $failure++) {
                Loop::futureTick(fn () => $this->hears('what Alice said in the call', "Failure {$failure}"));
            }

            Loop::addTimer(0.1, function () use (&$later) {
                $later = VoiceSession::forGuild(self::GUILD_ID) !== null;
                posix_kill(getmypid(), SIGTERM);
            });
        }, $app);

        $this->assertTrue($later, 'The bot was still running, in its call.');
        $this->assertSame(0, $code);
        $this->assertSame(
            array_map(fn (int $failure) => "Something failed in the event loop: Failure {$failure}", range(1, 9)),
            $this->loggedProblems(),
        );
        $failure = $this->logged('Something failed in the event loop: Failure 1')[0];
        $this->assertInstanceOf(RuntimeException::class, $failure['exception']);
        $this->assertStringEndsWith('ApplicationStopTest->hears()', $failure['trace'][0]);
        $this->assertLogsNeverMention('what Alice said');
        $this->assertNotContains(VoiceSession::LEFT, $this->sent);
    }

    public function testLeavesItsCallsAndEndsWhenTooMuchFailsInTheLoop(): void
    {
        VoiceSession::start($this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        $code = $this->runBot(function () {
            // As with a callback that throws every time it runs. Without an end, as nobody tells the bot to stop.
            for ($failure = 1; $failure <= 12; $failure++) {
                Loop::futureTick(fn () => throw new RuntimeException("Failure {$failure}"));
            }

            // Whoever watches it presses Ctrl+C while it is ending.
            Loop::addTimer(0.1, fn () => posix_kill(getmypid(), SIGINT));
        });

        // Not 0, whatever it is told while it ends: whatever runs the bot can start it again.
        $this->assertSame(1, $code);
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame([VoiceSession::LEFT], $this->sent, 'The call was told why the bot left it.');
        $this->assertSame([[false, 0]], $this->closed);
        // Each of the ten is logged, then that they are too many, and no more of them.
        $this->assertSame(
            [
                ...array_map(fn (int $failure) => "Something failed in the event loop: Failure {$failure}", range(1, 10)),
                'Too much is failing in the event loop: leaving every call and stopping',
                'Stopping now, without waiting for the calls',
            ],
            $this->loggedProblems(),
        );
        $this->assertSame(
            Level::Critical,
            array_values(array_filter($this->logs->getRecords(), fn ($record) => str_starts_with($record->message, 'Too much')))[0]->level,
        );
        $this->assertSame(['too many errors'], array_column($this->logged('Stopping the bot'), 'reason'));
    }

    public function testTenFailuresWithinTenSecondsAreTooMany(): void
    {
        $app = $this->app();
        // Nine things failed over the last five seconds.
        (new ReflectionProperty(Application::class, 'caughtAt'))->setValue($app, array_fill(0, 9, microtime(true) - 5));

        $code = $this->runBot(fn () => Loop::futureTick(fn () => throw new RuntimeException('The tenth')), $app);

        $this->assertSame(1, $code);
        $this->assertSame(
            [['failures' => 10, 'seconds' => 10.0]],
            $this->logged('Too much is failing in the event loop: leaving every call and stopping'),
        );
    }

    /**
     * Runs the bot until it ends by itself.
     *
     * @param Closure(): mixed $running Called once the bot runs, on its own event loop.
     * @return int What the process would end with.
     */
    private function runBot(Closure $running, ?Application $app = null): int
    {
        $app ??= $this->app();
        $app->discord->method('run')->willReturnCallback(function () use ($running) {
            $running();
            Loop::run();
        });

        try {
            return $app->run();
        } finally {
            // The bot is over: what the test does next runs on the tests' loop again.
            Loop::set($this->loop);
        }
    }

    /**
     * The bot, with a Discord client that never connects, and notes when it is closed.
     */
    private function app(): Application
    {
        $gate = new GatedLoop(Loop::get());
        $app = new Application(['token' => 'test-token', 'loop' => $gate, 'logger' => new Logger('test', [$this->logs])]);
        $gate->open = true;

        $app->discord = static::getStubBuilder(Discord::class)->disableOriginalConstructor()->onlyMethods(['run', 'close'])->getStub();
        $app->discord->method('close')->willReturnCallback(function (bool $closeLoop = true) {
            $this->closed[] = [$closeLoop, count($this->logged('Summarized the call'))];
            $this->closedAt = microtime(true);
        });

        return $app;
    }

    /**
     * Whether a process has ended, also when nothing has asked how it ended yet.
     */
    private function hasEnded(int $pid): bool
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");

        // After its name comes its state: Z for one that has ended.
        return $stat === false || preg_match('/\) Z /', $stat) === 1;
    }

    private function hears(string $said, string $failure): never
    {
        throw new RuntimeException($failure);
    }
}
