<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Voice\WhisperServer;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Http\Message\ResponseException;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use stdClass;
use Tests\RunsOutOfFileDescriptors;
use Tests\WaitsWithin;
use Tests\Wav;
use WeakReference;

use function React\Async\await;
use function React\Async\delay;

final class WhisperServerTest extends TestCase
{
    use RunsOutOfFileDescriptors;
    use WaitsWithin;

    private const array ENV = [
        'FAKE_ENV', 'FAKE_WHISPER_SERVER_LOG', 'FAKE_WHISPER_SERVER_LOAD', 'FAKE_WHISPER_SERVER_HEALTH_DELAY', 'FAKE_WHISPER_SERVER_STATUS',
        'FAKE_WHISPER_SERVER_DIE_AT', 'FAKE_WHISPER_SERVER_BODY', 'FAKE_WHISPER_HOLD', 'FAKE_WHISPER_DELAY', 'FAKE_WHISPER_OUTPUT',
    ];

    private string $folder;

    private string $log;

    /** While this file exists, the server doesn't answer. */
    private string $hold;

    /** A tenth of a second of silence. */
    private string $speech;

    /** @var list<array{string, string, array<string, mixed>}> What the server said: its level, message and context. */
    private array $said = [];

    /** @var array<int, array{WhisperServer, int}> Each server the test made, and how many times a call took it. */
    private array $servers = [];

    /** @var array<string, string> What the stand-in reads again with each request: see {@see fake()}. */
    private array $fakes = [];

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/whisper-server-' . uniqid();
        mkdir($this->folder);
        $this->log = "{$this->folder}/server.log";
        $this->hold = "{$this->folder}/hold";
        $this->speech = "{$this->folder}/speech.wav";
        file_put_contents($this->speech, Wav::silence(0.1));
        putenv("FAKE_WHISPER_SERVER_LOG={$this->log}");
        putenv("FAKE_WHISPER_HOLD={$this->hold}");
        putenv("FAKE_ENV={$this->folder}/fake.env");
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as [$server, $taken]) {
            for ($i = 0; $i < $taken; $i++) {
                $this->ends($server->release());
            }
        }

        foreach (self::ENV as $name) {
            putenv($name);
        }

        unset($_ENV['WHISPER_BINARY'], $_ENV['WHISPER_SERVER_BINARY']);
        exec('rm -rf ' . escapeshellarg($this->folder));
    }

    public function testStartsOnAPortAndAPathOfItsOwnWithTheModelAndTheBeamOfWhisperCli(): void
    {
        $this->started();

        $arguments = $this->arguments();
        $this->assertSame(['--model', '/models/ggml-base.bin', '--host', '127.0.0.1', '--port', '0', '--request-path'], array_slice($arguments, 0, 7));
        $this->assertMatchesRegularExpression('#^/[0-9a-f]{32}$#', $arguments[7], 'A path that nobody can guess.');
        // whisper-cli decodes with a beam of 5 and 5 candidates; the server's own default is greedy, which wrote other words.
        $this->assertSame(['--no-timestamps', '--beam-size', '5', '--best-of', '5'], array_slice($arguments, 8));
    }

    public function testUsesAsManyThreadsAsItIsTold(): void
    {
        $this->started(threads: 8);

        $this->assertSame(['--threads', '8'], array_slice($this->arguments(), -2));
    }

    public function testEachStartChoosesAPortAndAPathOfItsOwn(): void
    {
        $server = $this->started();
        $this->ends($this->giveBack($server));
        $this->take($server);
        $this->becomesReady($server);

        $this->assertCount(2, $this->ports());
        $this->assertNotSame($this->ports()[0], $this->ports()[1], 'A server that is going could still have its port.');
        $this->assertCount(2, $this->paths());
        $this->assertNotSame($this->paths()[0], $this->paths()[1], 'Nor its path.');
        $this->assertCount(2, $this->pids());
    }

    public function testIsNotReadyBeforeItHasLoadedItsModel(): void
    {
        putenv('FAKE_WHISPER_SERVER_LOAD=0.6');
        $server = $this->server();
        $this->take($server);

        $this->assertFalse($server->isReady());

        try {
            await($server->transcribe($this->speech, 'en', '', 1.0));
            $this->fail('A server that is loading should have refused.');
        } catch (RuntimeException $e) {
            $this->assertSame('The whisper server is not ready.', $e->getMessage());
        }

        $this->becomesReady($server);
        $this->assertCount(1, $this->said);
        $this->assertSame(['info', 'Whisper server ready'], array_slice($this->said[0], 0, 2));
        $this->assertGreaterThanOrEqual(600, $this->said[0][2]['ms'], 'How long it took, to load its model first.');
        $this->assertSame((int) $this->ports()[0], $this->said[0][2]['port'], 'The port of the server that was started, as the system says.');
    }

    public function testIsNotReadyWhileItWarmsUp(): void
    {
        putenv('FAKE_WHISPER_DELAY=1');
        $server = $this->server();
        $this->take($server);
        $this->waitUntil(fn () => file_exists($this->log) && $this->requests() !== [], 'the warm-up to begin');

        $this->assertFalse($server->isReady(), 'The warm-up is not answered yet.');
        $this->assertSame([], $this->said);

        $this->becomesReady($server);
    }

    public function testWarmsUpWithASecondOfSilenceBeforeItIsReady(): void
    {
        $this->started();

        // 16 kHz, 16 bits, mono: a header of 44 bytes and 32000 of silence, in a file the server can read. That is nobody's question.
        $this->assertSame(['request language=en prompt=- format=json filename=audio.wav bytes=32044 wav=16000Hz,1ch,16bit,32000bytes'], $this->requests());
    }

    public function testAsksOnlyUnderItsPath(): void
    {
        $server = $this->started();

        await($server->transcribe($this->speech, 'en', '', 1.0));

        // The stand-in does not know any other path, like the real server started with --request-path.
        $this->assertSame([], $this->refused());
        $this->assertCount(2, $this->requests());
    }

    public function testTranscribesWithTheLanguageAndThePromptItIsGiven(): void
    {
        $server = $this->started();

        $text = await($server->transcribe($this->speech, 'pt', 'A voice call with Claude.', 1.0));

        $this->assertSame(" [BLANK_AUDIO]\n Hey Claude, (coughs) what time is it?\n", $text, 'As whisper wrote it: the annotations are for Transcriber to take out.');
        $this->assertSame(
            sprintf('request language=pt prompt=A voice call with Claude. format=json filename=audio.wav bytes=%d wav=16000Hz,1ch,16bit,3200bytes', filesize($this->speech)),
            $this->requests()[1],
            'The recording is sent whole, under a name that says nothing about it.',
        );
    }

    public function testSendsNoPromptWhenThereIsNone(): void
    {
        $server = $this->started();

        await($server->transcribe($this->speech, 'en', '', 1.0));

        $this->assertStringStartsWith('request language=en prompt=- format=json', $this->requests()[1]);
    }

    public function testGivesARequestTimeForTheLengthOfTheAudio(): void
    {
        putenv('FAKE_WHISPER_DELAY=0.6');
        $server = $this->started(minimumTimeout: 0.3);

        // 0.3 s would not do for a request that takes 0.6, and 0.3 + 0.2 for each of 5 seconds of audio does.
        $text = await($server->transcribe($this->speech, 'en', '', 5.0));

        $this->assertStringContainsString('what time is it?', $text);
    }

    public function testRefusesAFileItCannotRead(): void
    {
        $server = $this->started();

        try {
            await($server->transcribe("{$this->folder}/gone.wav", 'en', '', 1.0));
            $this->fail('A file that is not there should have been refused.');
        } catch (RuntimeException $e) {
            $this->assertSame("Could not read {$this->folder}/gone.wav.", $e->getMessage());
        }

        $this->assertTrue($server->isReady(), 'That was not the server\'s doing.');
    }

    public function testAnAnswerThatSaysNoIsNotTheServersFault(): void
    {
        $server = $this->started();
        $this->fake('FAKE_WHISPER_SERVER_STATUS', '500');

        try {
            await($server->transcribe($this->speech, 'en', '', 1.0));
            $this->fail('The server said no.');
        } catch (ResponseException $e) {
            $this->assertSame(500, $e->getCode());
        }

        $this->assertTrue($server->isReady(), 'A file it cannot read is no reason to give it up.');
        $this->assertCount(1, $this->said, 'It said it was ready, and nothing else.');
    }

    public function testAnAnswerWithoutAnythingHeardIsTheServersFault(): void
    {
        $server = $this->started(restartAfter: 60.0);
        $this->fake('FAKE_WHISPER_SERVER_BODY', '{"error":"what was said is not in here"}');

        try {
            await($server->transcribe($this->speech, 'en', '', 1.0));
            $this->fail('The server did not say what it heard.');
        } catch (RuntimeException $e) {
            $this->assertSame('The whisper server did not say what it heard.', $e->getMessage(), 'Not what it sent instead: that could hold what someone said.');
        }

        $this->assertFalse($server->isReady());
        $this->assertSame('warning', $this->said[1][0]);
        $this->assertSame('The whisper server stopped (The whisper server did not say what it heard) and starts again.', $this->said[1][1]);
    }

    public function testAServerThatDoesNotAnswerInTimeIsGivenUpAndStartedAgain(): void
    {
        $server = $this->started(restartAfter: 0.2, minimumTimeout: 0.3);
        // From now on the server holds every request.
        touch($this->hold);

        try {
            await($server->transcribe($this->speech, 'en', '', 0.0));
            $this->fail('The server should have taken too long.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Request timed out after 0.3', $e->getMessage());
        }

        $this->assertFalse($server->isReady(), 'It is not asked again before it is back.');
        $this->assertSame('warning', $this->said[1][0]);
        $this->assertStringStartsWith('The whisper server stopped (Request timed out after 0.3', $this->said[1][1]);
        $this->assertStringEndsWith(') and starts again.', $this->said[1][1]);

        unlink($this->hold);
        $this->waitUntil(fn () => count($this->said) === 3, 'the server to be back');
        $this->assertSame('Whisper server ready', $this->said[2][1]);
        $this->assertTrue($server->isReady());
        $this->assertCount(2, $this->pids(), 'A new process: the one that did not answer is not asked again.');
    }

    public function testAServerThatDiesAnsweringIsStartedAgain(): void
    {
        // The warm-up is its first request, and it dies on the next.
        putenv('FAKE_WHISPER_SERVER_DIE_AT=2');
        $server = $this->started(restartAfter: 0.2);

        try {
            await($server->transcribe($this->speech, 'en', '', 1.0));
            $this->fail('The server should have died.');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(ResponseException::class, $e, 'It never said anything.');
        }

        $this->assertFalse($server->isReady());
        $this->waitUntil(fn () => $server->isReady(), 'the server to be back');
        $this->assertCount(2, $this->pids());
    }

    public function testAServerThatEndsByItselfIsStartedAgain(): void
    {
        $server = $this->started(restartAfter: 0.2);

        posix_kill($this->pids()[0], SIGKILL);

        $this->waitUntil(fn () => count($this->said) >= 2, 'the server to be missed');
        $this->assertFalse($server->isReady());
        $this->assertSame(['warning', 'The whisper server stopped (setpriv was killed by signal 9) and starts again.'], array_slice($this->said[1], 0, 2));
        $this->waitUntil(fn () => $server->isReady(), 'the server to be back');
        $this->assertCount(2, $this->pids());
    }

    public function testStartsAgainOnceTheTimeToRestartHasPassed(): void
    {
        $server = $this->started(restartAfter: 1.5);

        posix_kill($this->pids()[0], SIGKILL);
        $this->waitUntil(fn () => count($this->said) >= 2, 'the server to be missed');
        delay(0.6);

        $this->assertCount(1, $this->pids(), 'Not yet.');
        $this->waitUntil(fn () => $server->isReady(), 'the server to be back');
        $this->assertCount(2, $this->pids());
    }

    public function testStartsAtOnceWhenACallTakesTheServerWhileItWaitsToStartAgain(): void
    {
        $server = $this->started(restartAfter: 1.0);

        posix_kill($this->pids()[0], SIGKILL);
        $this->waitUntil(fn () => count($this->said) >= 2, 'the server to be missed');

        // A second call, which starts it without waiting.
        $this->take($server);
        $this->becomesReady($server);
        delay(1.4);

        $this->assertCount(2, $this->pids(), 'The wait that was left was over, and started nothing more.');
    }

    public function testDoesNotStartAServerThatNeverServedAgain(): void
    {
        // It would only fail the same way.
        $server = $this->server(binary: 'false', restartAfter: 0.1);
        $this->take($server);

        $this->waitUntil(fn () => count($this->said) > 0, 'the failure to be told');
        delay(0.5);

        $this->assertSame([['warning', 'The whisper server stopped (setpriv exited with code 1).', []]], $this->said);
        $this->assertFalse($server->isReady());
    }

    public function testGivesUpAServerThatDoesNotListenInTime(): void
    {
        putenv('FAKE_WHISPER_SERVER_LOAD=5');
        $server = $this->server(startupSeconds: 0.3, restartAfter: 0.1);
        $this->take($server);

        $this->waitUntil(fn () => count($this->said) > 0, 'the server to be given up');
        delay(0.4);

        $this->assertSame([['warning', 'The whisper server stopped (it did not start within 0.3s).', []]], $this->said);
        $this->assertCount(1, $this->pids(), 'And not tried again.');
        $this->assertFalse($server->isReady());
    }

    public function testGivesUpAServerThatFailsItsFirstRequest(): void
    {
        putenv('FAKE_WHISPER_SERVER_STATUS=500');
        $server = $this->server(restartAfter: 0.1);
        $this->take($server);

        $this->waitUntil(fn () => count($this->said) > 0, 'the server to be given up');
        delay(0.5);

        $this->assertSame([['warning', 'The whisper server stopped (its first request failed: HTTP status code 500 (Fake)).', []]], $this->said);
        $this->assertCount(1, $this->pids(), 'It never served: it would fail the same way.');
        $this->assertFalse($server->isReady());
    }

    public function testGivesUpAServerThatDiesOnItsFirstRequest(): void
    {
        putenv('FAKE_WHISPER_SERVER_DIE_AT=1');
        $server = $this->server(restartAfter: 0.1);
        $this->take($server);

        $this->waitUntil(fn () => count($this->said) > 0, 'the server to be given up');
        delay(0.5);

        // Which it notices first, that its request was never answered or that it ended, is up to the event loop.
        $this->assertCount(1, $this->said);
        $this->assertStringStartsWith('The whisper server stopped (', $this->said[0][1]);
        $this->assertStringEndsWith(').', $this->said[0][1], 'Not "and starts again": it never served.');
        $this->assertCount(1, $this->pids());
        $this->assertFalse($server->isReady());
    }

    public function testGivesUpAServerThatDoesNotSayThatItIsHealthy(): void
    {
        putenv('FAKE_WHISPER_SERVER_HEALTH_DELAY=3');
        $server = $this->server(restartAfter: 0.1);
        $this->take($server);

        $this->waitUntil(fn () => count($this->said) > 0, 'the server to be given up', 6.0);
        delay(0.4);

        $this->assertSame([['warning', 'The whisper server stopped (it did not say that it is healthy: Request timed out after 2 seconds).', []]], $this->said);
        $this->assertCount(1, $this->pids());
    }

    public function testStopsOnceTheLastCallHasGivenItBack(): void
    {
        $server = $this->started();
        $this->take($server);
        $pid = $this->pids()[0];

        $this->ends($this->giveBack($server));

        $this->assertTrue($server->isReady(), 'Another call still has it.');
        $this->assertTrue(posix_kill($pid, 0));

        $this->ends($this->giveBack($server));

        $this->assertFalse($server->isReady());
        $this->assertFalse(posix_kill($pid, 0), 'Its process is gone by the time the last call has given it back.');
    }

    public function testEndsWithTheBotThatStartedIt(): void
    {
        // A bot that is killed gives nothing back: its server would go on listening, and keep its model in memory.
        $script = "{$this->folder}/bot.php";
        file_put_contents($script, sprintf(
            '<?php require %s; $server = new App\Voice\WhisperServer(%s, "/models/ggml-base.bin"); $server->acquire(function () {}); React\Async\delay(1.5); posix_kill(getmypid(), SIGKILL);',
            var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
            var_export(__DIR__ . '/../../Fixtures/fake-whisper-server', true),
        ));

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>/dev/null');
        $pid = $this->pids()[0];

        try {
            $this->waitUntil(fn () => ! posix_kill($pid, 0), 'the server to end with the bot', 5.0);
            $this->assertFalse(posix_kill($pid, 0));
        } finally {
            // A server that did not end is not left running, whatever the test found.
            posix_kill($pid, SIGKILL);
        }
    }

    public function testGivingBackWhatWasNeverTakenChangesNothing(): void
    {
        $server = $this->started();

        $this->ends($this->giveBack($server));
        $this->ends($server->release());

        $this->assertFalse($server->isReady());
        $this->assertCount(1, $this->pids());
        $this->take($server);
        $this->take($server);
        $this->becomesReady($server);
        $this->assertCount(2, $this->pids(), 'Taken again, it is a new server.');

        $this->ends($this->giveBack($server));

        $this->assertTrue($server->isReady(), 'The release that was never a take did not give one of these back.');
    }

    public function testStartsANewServerWhileTheOneOfTheLastCallIsStillEnding(): void
    {
        $server = $this->started();
        $pid = $this->pids()[0];

        $ended = $this->giveBack($server);
        $this->take($server);
        $this->ends($ended);

        $this->assertFalse(posix_kill($pid, 0));
        $this->becomesReady($server);
        $this->assertNotSame($pid, $this->pids()[1]);
        $this->assertNotSame($this->ports()[0], $this->ports()[1]);
    }

    public function testAServerThatCannotBeStartedIsDoneWithout(): void
    {
        $server = $this->started();
        $this->ends($this->giveBack($server));
        $this->said = [];

        // The bot has no file descriptors left to start it with: whatever it needs is loaded already.
        $this->withoutFileDescriptors(function () use ($server) {
            $this->take($server);
        });

        $this->assertFalse($server->isReady());
        $this->assertCount(1, $this->said);
        $this->assertSame('warning', $this->said[0][0]);
        $this->assertStringStartsWith('The whisper server stopped (Unable to launch a new process: ', $this->said[0][1]);
    }

    public function testAServerThatIsStillLoadingIsLeftAloneOnceTheLastCallIsGone(): void
    {
        putenv('FAKE_WHISPER_SERVER_LOAD=1');
        $server = $this->server();
        $this->take($server);

        $this->ends($this->giveBack($server));
        delay(1.4);

        $this->assertFalse($server->isReady(), 'It was not asked whether it listens after the call was over.');
        $this->assertSame([], $this->said);
    }

    public function testIsNotWarmedUpOnceTheLastCallIsGone(): void
    {
        putenv('FAKE_WHISPER_SERVER_HEALTH_DELAY=1');
        $server = $this->server();
        $this->take($server);
        // The server listens, and is still saying that it is healthy when the call is over.
        delay(0.6);

        $this->ends($this->giveBack($server));
        delay(1.0);

        $this->assertSame([], $this->requests(), 'Nobody asked it to warm up.');
        $this->assertFalse($server->isReady());
        $this->assertSame([], $this->said);
    }

    public function testIsNotReadyOnceTheLastCallIsGoneWhileItWarmsUp(): void
    {
        putenv('FAKE_WHISPER_DELAY=1');
        $server = $this->server();
        $this->take($server);
        $this->waitUntil(fn () => file_exists($this->log) && $this->requests() !== [], 'the warm-up to begin');

        $this->ends($this->giveBack($server));
        delay(1.0);

        $this->assertFalse($server->isReady(), 'The warm-up answer comes to a server nobody has.');
        $this->assertSame([], $this->said);
    }

    public function testForgetsTheCallOnceTheLastCallIsGone(): void
    {
        $call = new stdClass();
        $remembered = WeakReference::create($call);
        $server = $this->server();
        $server->acquire(function (string $level, string $message) use ($call) {
            $call->said[] = $message;
        });
        $this->servers[spl_object_id($server)][1]++;
        $this->becomesReady($server);

        $this->ends($this->giveBack($server));
        unset($call);
        gc_collect_cycles();

        $this->assertNull($remembered->get(), 'A finished call is not kept alive by the server it had.');
    }

    public function testTellsTheCallThatTookTheServerLast(): void
    {
        $first = $second = [];
        $server = $this->server();
        $server->acquire(function (string $level, string $message) use (&$first) {
            $first[] = $message;
        });
        $server->acquire(function (string $level, string $message) use (&$second) {
            $second[] = $message;
        });
        $this->servers[spl_object_id($server)][1] += 2;

        $this->becomesReady($server);

        $this->assertSame([], $first);
        $this->assertSame(['Whisper server ready'], $second);
    }

    public function testTheServerIsTheWhisperServerNextToWhisperCli(): void
    {
        $_ENV['WHISPER_BINARY'] = "{$this->folder}/whisper-cli";
        $this->assertNull(WhisperServer::fromEnv('/models/m.bin', null), 'Nothing is there to run.');

        touch("{$this->folder}/whisper-server");
        $this->assertNull(WhisperServer::fromEnv('/models/m.bin', null), 'It cannot be run.');

        chmod("{$this->folder}/whisper-server", 0755);
        $server = WhisperServer::fromEnv('/models/m.bin', 4);

        $this->assertSame("{$this->folder}/whisper-server", $server->binary);
        $this->assertSame('/models/m.bin', $server->model);
        $this->assertSame(4, $server->threads);
        $this->assertSame($server, WhisperServer::fromEnv('/models/m.bin', 4), 'Every call has the same one.');
        $this->assertNotSame($server, WhisperServer::fromEnv('/models/m.bin', null), 'Not one that was started with another number of threads...');
        $this->assertNotSame($server, WhisperServer::fromEnv('/models/other.bin', 4), '...nor another model.');
    }

    public function testAnotherServerCanBeSetInEnv(): void
    {
        $_ENV['WHISPER_BINARY'] = 'whisper-cli';
        $_ENV['WHISPER_SERVER_BINARY'] = "{$this->folder}/elsewhere/whisper-server";

        $this->assertSame("{$this->folder}/elsewhere/whisper-server", WhisperServer::fromEnv('/models/m.bin', null)->binary, 'Not checked: a wrong path is told when the server fails to start.');
    }

    public function testNoServerWhenEnvSaysNone(): void
    {
        // The server is where whisper-cli is, so that it is only env that turns it off.
        $_ENV['WHISPER_BINARY'] = "{$this->folder}/whisper-cli";
        touch("{$this->folder}/whisper-server");
        chmod("{$this->folder}/whisper-server", 0755);
        $this->assertNotNull(WhisperServer::fromEnv('/models/m.bin', null));

        $_ENV['WHISPER_SERVER_BINARY'] = '';
        $this->assertNull(WhisperServer::fromEnv('/models/m.bin', null), 'Empty leaves the transcribing to whisper-cli, even where the server is next to it.');

        // What env() makes of "false" in a .env file.
        $_ENV['WHISPER_SERVER_BINARY'] = 'false';
        $this->assertNull(WhisperServer::fromEnv('/models/m.bin', null));
    }

    public function testNullAndTrueLeaveItToTheServerNextToWhisperCli(): void
    {
        $_ENV['WHISPER_BINARY'] = "{$this->folder}/whisper-cli";
        touch("{$this->folder}/whisper-server");
        chmod("{$this->folder}/whisper-server", 0755);

        foreach (['null', 'true'] as $value) {
            $_ENV['WHISPER_SERVER_BINARY'] = $value;
            $this->assertSame("{$this->folder}/whisper-server", WhisperServer::fromEnv('/models/m.bin', null)->binary, "WHISPER_SERVER_BINARY={$value}");
        }
    }

    public function testThereIsNothingNextToAWhisperCliThatIsOnThePath(): void
    {
        $_ENV['WHISPER_BINARY'] = 'whisper-cli';
        unset($_ENV['WHISPER_SERVER_BINARY']);

        $this->assertNull(WhisperServer::fromEnv('/models/m.bin', null));
    }

    private function server(string $binary = __DIR__ . '/../../Fixtures/fake-whisper-server', ?int $threads = null, float $startupSeconds = 60.0, float $restartAfter = 5.0, float $minimumTimeout = 3.0): WhisperServer
    {
        $server = new WhisperServer($binary, '/models/ggml-base.bin', $threads, $startupSeconds, $restartAfter, $minimumTimeout);
        $this->servers[spl_object_id($server)] = [$server, 0];

        return $server;
    }

    /**
     * A call takes the server.
     */
    private function take(WhisperServer $server): void
    {
        $server->acquire($this->logTo(...));
        $this->servers[spl_object_id($server)][1]++;
    }

    /**
     * A call gives the server back.
     *
     * @return PromiseInterface<mixed>
     */
    private function giveBack(WhisperServer $server): PromiseInterface
    {
        $this->servers[spl_object_id($server)][1] = max(0, $this->servers[spl_object_id($server)][1] - 1);

        return $server->release();
    }

    /**
     * A server that a call took, and that is ready.
     */
    private function started(?int $threads = null, float $restartAfter = 5.0, float $minimumTimeout = 3.0): WhisperServer
    {
        $server = $this->server(threads: $threads, restartAfter: $restartAfter, minimumTimeout: $minimumTimeout);
        $this->take($server);
        $this->becomesReady($server);

        return $server;
    }

    /**
     * Sets what the stand-in reads again with each request, which the server that is running has already started with.
     */
    private function fake(string $name, string $value): void
    {
        $this->fakes[$name] = $value;
        file_put_contents("{$this->folder}/fake.env", implode('', array_map(
            fn (string $name, string $value) => sprintf("%s='%s'\n", $name, str_replace("'", "'\\''", $value)),
            array_keys($this->fakes),
            $this->fakes,
        )));
    }

    /**
     * @param PromiseInterface<mixed> $ended
     */
    private function ends(PromiseInterface $ended): void
    {
        $this->within(10.0, $ended, 'the whisper server to end');
    }

    private function becomesReady(WhisperServer $server): void
    {
        $this->waitUntil(fn () => $server->isReady(), 'the whisper server to be ready');
    }

    /**
     * Runs the event loop until the condition holds, failing the test after the timeout.
     */
    private function waitUntil(callable $condition, string $what, float $timeout = 10.0): void
    {
        $done = new Deferred();
        $check = Loop::addPeriodicTimer(0.05, function () use ($condition, $done) {
            if ($condition()) {
                $done->resolve(null);
            }
        });
        $deadline = Loop::addTimer($timeout, fn () => $done->resolve(null));

        await($done->promise());
        Loop::cancelTimer($check);
        Loop::cancelTimer($deadline);

        $this->assertTrue((bool) $condition(), "Timed out waiting for {$what}.");
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logTo(string $level, string $message, array $context = []): void
    {
        $this->said[] = [$level, $message, $context];
    }

    /**
     * @return list<string> The arguments of the first server that was started.
     */
    private function arguments(): array
    {
        preg_match('/^pid=\d+\n((?:arg=.*\n)+)/m', file_get_contents($this->log), $found);

        return array_map(fn (string $line) => substr($line, strlen('arg=')), explode("\n", trim($found[1])));
    }

    /**
     * @return list<string> The port each server that was started listens on.
     */
    private function ports(): array
    {
        preg_match_all('/^port=(\d+)$/m', file_get_contents($this->log), $found);

        return $found[1];
    }

    /**
     * @return list<string> The path each server that was started was told to answer under.
     */
    private function paths(): array
    {
        preg_match_all('/^arg=--request-path\narg=(\S+)$/m', file_get_contents($this->log), $found);

        return $found[1];
    }

    /**
     * @return list<int> The process ID of each server that was started.
     */
    private function pids(): array
    {
        preg_match_all('/^pid=(\d+)$/m', file_get_contents($this->log), $found);

        return array_map(intval(...), $found[1]);
    }

    /**
     * @return list<string> The requests the servers got, in order, the warm-up of each first.
     */
    private function requests(): array
    {
        return array_values(array_filter(explode("\n", trim(file_get_contents($this->log))), fn (string $line) => str_starts_with($line, 'request ')));
    }

    /**
     * @return list<string> The requests the servers turned down, for being outside the path they were started with.
     */
    private function refused(): array
    {
        return array_values(array_filter(explode("\n", trim(file_get_contents($this->log))), fn (string $line) => str_starts_with($line, 'refused ')));
    }
}
