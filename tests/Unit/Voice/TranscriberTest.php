<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Support\CommandFailedException;
use App\Voice\Transcriber;
use App\Voice\WhisperServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;

use function React\Async\await;

final class TranscriberTest extends TestCase
{
    /** @var list<WhisperServer> The servers a call took, in the tests that have one. */
    private array $servers = [];

    private string $folder = '';

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            await($server->release());
        }

        $this->servers = [];

        foreach (['FAKE_WHISPER_LOG', 'FAKE_WHISPER_SERVER_LOG', 'FAKE_WHISPER_SERVER_LOAD', 'FAKE_WHISPER_SERVER_STATUS'] as $name) {
            putenv($name);
        }

        if ($this->folder !== '') {
            exec('rm -rf ' . escapeshellarg($this->folder));
        }

        unset($_ENV['WHISPER_LANGUAGE'], $_ENV['WHISPER_PROMPT'], $_ENV['WHISPER_THREADS'], $_ENV['WHISPER_BINARY'], $_ENV['WHISPER_SERVER_BINARY'], $_ENV['WHISPER_MODEL']);
    }

    public function testHasNoServerUnlessThereIsOneNextToWhisperCli(): void
    {
        $this->assertNull(Transcriber::fromEnv()->server);

        $this->folder = sys_get_temp_dir() . '/transcriber-' . uniqid();
        mkdir($this->folder);
        $_ENV['WHISPER_BINARY'] = "{$this->folder}/whisper-cli";
        $_ENV['WHISPER_MODEL'] = '/models/ggml-base.bin';
        $_ENV['WHISPER_THREADS'] = '6';
        touch("{$this->folder}/whisper-server");
        chmod("{$this->folder}/whisper-server", 0755);

        $server = Transcriber::fromEnv('pt')->server;

        $this->assertSame("{$this->folder}/whisper-server", $server->binary);
        $this->assertSame('/models/ggml-base.bin', $server->model);
        $this->assertSame(6, $server->threads);
        $this->assertSame($server, Transcriber::fromEnv()->server, 'The same server whatever language a call speaks: it is told with each utterance.');
    }

    public function testAServerThatIsReadyTranscribesInsteadOfWhisperCli(): void
    {
        $transcriber = $this->transcriberWithServer(language: 'pt', prompt: 'A voice call with Claude.');

        $text = await($transcriber->transcribe($this->wav(), seconds: 2.0));

        $this->assertSame('Hey Claude, what time is it?', $text, 'Without the annotations, like whisper-cli\'s.');
        $this->assertSame('untouched', file_get_contents("{$this->folder}/cli.log"), 'whisper-cli did not run.');
        $this->assertStringContainsString('request language=pt prompt=A voice call with Claude. format=json', file_get_contents("{$this->folder}/server.log"));
    }

    public function testWhisperCliTranscribesWhileTheServerLoads(): void
    {
        putenv('FAKE_WHISPER_SERVER_LOAD=5');
        $transcriber = $this->transcriberWithServer(ready: false);
        $said = [];

        $text = await($transcriber->transcribe($this->wav(), log: function (string $level, string $message) use (&$said) {
            $said[] = $message;
        }));

        $this->assertSame('Hey Claude, what time is it?', $text);
        $this->assertSame([], $said, 'The server was not asked, so it did not fail.');
        $this->assertStringContainsString("arg=--file\narg={$this->wav()}", file_get_contents("{$this->folder}/cli.log"));
    }

    public function testWhisperCliTranscribesWhatIsTooLongForTheServer(): void
    {
        $transcriber = $this->transcriberWithServer();
        $said = [];
        $log = function (string $level, string $message) use (&$said) {
            $said[] = $message;
        };

        await($transcriber->transcribe($this->wav(), seconds: 30.5, log: $log));

        $this->assertStringContainsString("arg=--file\narg={$this->wav()}", file_get_contents("{$this->folder}/cli.log"), 'Longer than an utterance in a call can be.');
        $this->assertSame([], $said, 'The server was not asked.');

        file_put_contents("{$this->folder}/cli.log", 'untouched');
        await($transcriber->transcribe($this->wav(), seconds: Transcriber::SERVER_MAX_SECONDS, log: $log));

        $this->assertSame('untouched', file_get_contents("{$this->folder}/cli.log"), 'As long as an utterance can be, it is the server\'s.');
    }

    public function testWhisperCliTranscribesWhenTheServerFails(): void
    {
        putenv('FAKE_WHISPER_SERVER_STATUS=500');
        $transcriber = $this->transcriberWithServer();
        $said = [];

        $text = await($transcriber->transcribe($this->wav(), log: function (string $level, string $message) use (&$said) {
            $said[] = [$level, $message];
        }));

        $this->assertSame('Hey Claude, what time is it?', $text, 'The utterance is not lost.');
        $this->assertStringContainsString("arg=--file\narg={$this->wav()}", file_get_contents("{$this->folder}/cli.log"));
        $this->assertCount(1, $said);
        $this->assertSame(['warning', 'The whisper server could not transcribe, whisper-cli does: HTTP status code 500 (Fake)'], $said[0], 'Without what anyone said: the logs never hold that.');
    }

    public function testWhisperCliTranscribesWhenTheServerFailsWithoutAnyoneToTell(): void
    {
        putenv('FAKE_WHISPER_SERVER_STATUS=500');
        $transcriber = $this->transcriberWithServer();

        $this->assertSame('Hey Claude, what time is it?', await($transcriber->transcribe($this->wav())));
    }

    public function testUsesAsManyThreadsAsEnvSays(): void
    {
        // Left to whisper: 4, or as many as the CPU has when that is fewer.
        $this->assertNull(Transcriber::fromEnv()->threads);

        $_ENV['WHISPER_THREADS'] = '8';
        $this->assertSame(8, Transcriber::fromEnv()->threads);
        $this->assertSame(8, Transcriber::fromEnv('pt')->threads, 'Whatever language a server speaks.');

        $_ENV['WHISPER_THREADS'] = '1';
        $this->assertSame(1, Transcriber::fromEnv()->threads);

        // whisper would refuse anything but a whole number of threads, 1 or more, for every utterance.
        foreach (['0', '-2', 'many', '2.5', ''] as $threads) {
            $_ENV['WHISPER_THREADS'] = $threads;
            $this->assertNull(Transcriber::fromEnv()->threads, "WHISPER_THREADS={$threads}");
        }
    }

    public function testAServersLanguageReplacesTheOneInEnv(): void
    {
        $this->assertSame('auto', Transcriber::fromEnv()->language);

        $_ENV['WHISPER_LANGUAGE'] = 'en';

        $this->assertSame('en', Transcriber::fromEnv()->language);
        $this->assertSame('pt', Transcriber::fromEnv('pt')->language);
    }

    public function testKnowsTheLanguagesWhisperTranscribes(): void
    {
        // The codes of whisper.cpp's language table (g_lang in src/whisper.cpp).
        $this->assertCount(100, Transcriber::LANGUAGES);
        $this->assertSame(Transcriber::LANGUAGES, array_unique(Transcriber::LANGUAGES));
        $this->assertContains('en', Transcriber::LANGUAGES);
        $this->assertContains('pt', Transcriber::LANGUAGES);
        $this->assertContains('yue', Transcriber::LANGUAGES);
        $this->assertNotContains('auto', Transcriber::LANGUAGES, 'Detecting the language is not a language.');

        foreach (Transcriber::LANGUAGES as $code) {
            $this->assertMatchesRegularExpression('/^[a-z]{2,3}$/', $code);
        }
    }

    public function testRunsWhisperAndCleansItsOutput(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'fake-whisper-');
        putenv("FAKE_WHISPER_LOG={$log}");

        $transcriber = new Transcriber(__DIR__ . '/../../Fixtures/fake-whisper', '/models/ggml-base.bin', 'auto');
        $text = await($transcriber->transcribe('/recordings/utterance-1.wav'));

        $this->assertSame('Hey Claude, what time is it?', $text);
        $this->assertSame(
            "arg=--model\narg=/models/ggml-base.bin\narg=--language\narg=auto\narg=--no-timestamps\narg=--no-prints\narg=--file\narg=/recordings/utterance-1.wav\n",
            file_get_contents($log),
            'Without a number of threads, whisper is not told one: it takes as many as it does by itself.',
        );

        // More threads transcribe faster, up to what the CPU has.
        $transcriber = new Transcriber(__DIR__ . '/../../Fixtures/fake-whisper', '/models/ggml-base.bin', 'en', threads: 8);
        await($transcriber->transcribe('/recordings/utterance-2.wav'));

        $this->assertStringContainsString("arg=--language\narg=en\narg=--threads\narg=8\n", file_get_contents($log));

        putenv('FAKE_WHISPER_LOG');
        unlink($log);
    }

    public function testKillsWhisperWhenItTakesLongerThanTheTimeout(): void
    {
        putenv('FAKE_WHISPER_DELAY=1');

        try {
            await($this->slowTranscriber()->transcribe('/recordings/utterance-1.wav'));
            $this->fail('whisper should have timed out.');
        } catch (CommandFailedException $e) {
            $this->assertStringContainsString('fake-whisper timed out after 0.2s', $e->getMessage());
        } finally {
            putenv('FAKE_WHISPER_DELAY');
        }
    }

    public function testGivesWhisperTimeForTheLengthOfTheAudio(): void
    {
        putenv('FAKE_WHISPER_DELAY=1');

        try {
            // The same whisper that timed out after 0.2 seconds has 3 seconds for each second of audio, so it has 3 here.
            $this->assertSame('Hey Claude, what time is it?', await($this->slowTranscriber()->transcribe('/recordings/utterance-1.wav', seconds: 1.0)));
        } finally {
            putenv('FAKE_WHISPER_DELAY');
        }
    }

    public function testDefaultsToTwoMinutesForAudioOfUnknownLength(): void
    {
        $this->assertSame(120.0, (new Transcriber('whisper-cli', '/models/ggml-base.bin', 'auto'))->minimumTimeout);
        $this->assertSame(120.0, Transcriber::fromEnv()->minimumTimeout);
        $this->assertSame(3.0, Transcriber::SECONDS_PER_SECOND_OF_AUDIO);
    }

    public function testPassesTheWhisperPromptToWhisper(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'fake-whisper-pr23-');
        putenv("FAKE_WHISPER_LOG={$log}");

        $transcriber = new Transcriber(__DIR__ . '/../../Fixtures/fake-whisper', '/models/ggml-base.bin', 'en', 'A voice call with the assistant Claude.');
        await($transcriber->transcribe('/recordings/utterance-1.wav'));

        $this->assertSame(
            "arg=--model\narg=/models/ggml-base.bin\narg=--language\narg=en\narg=--prompt\narg=A voice call with the assistant Claude.\narg=--no-timestamps\narg=--no-prints\narg=--file\narg=/recordings/utterance-1.wav\n",
            file_get_contents($log),
        );

        putenv('FAKE_WHISPER_LOG');
        unlink($log);
    }

    public function testReadsThePromptFromTheEnvironment(): void
    {
        $_ENV['WHISPER_PROMPT'] = '  Hey Claude.  ';
        $this->assertSame('Hey Claude.', Transcriber::fromEnv()->prompt);

        $_ENV['WHISPER_PROMPT'] = '';
        $this->assertSame('', Transcriber::fromEnv()->prompt, 'Empty means no prompt.');

        unset($_ENV['WHISPER_PROMPT']);
        $this->assertSame('', Transcriber::fromEnv()->prompt, 'So does not setting it.');
    }

    #[DataProvider('outputs')]
    public function testClean(string $output, string $expected): void
    {
        $this->assertSame($expected, Transcriber::clean($output));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function outputs(): iterable
    {
        yield 'silence' => [" [BLANK_AUDIO]\n", ''];
        yield 'sounds only' => [' (keyboard clicking) [MUSIC]', ''];
        yield 'speech over lines' => [" Hello there.\n How are you?\n", 'Hello there. How are you?'];
        yield 'speech with annotations' => [' [laughs] That is funny (coughs) indeed.', 'That is funny indeed.'];
    }

    private function wav(): string
    {
        return "{$this->folder}/utterance.wav";
    }

    /**
     * A transcriber with a server that a call took. Its whisper-cli and its server log to files of their own,
     * so that a test can tell which of them ran.
     */
    private function transcriberWithServer(string $language = 'en', string $prompt = '', bool $ready = true): Transcriber
    {
        $this->folder = sys_get_temp_dir() . '/transcriber-' . uniqid();
        mkdir($this->folder);
        file_put_contents("{$this->folder}/cli.log", 'untouched');
        file_put_contents($this->wav(), 'RIFF-pretend-this-is-a-wav-file');
        putenv("FAKE_WHISPER_LOG={$this->folder}/cli.log");
        putenv("FAKE_WHISPER_SERVER_LOG={$this->folder}/server.log");

        $server = new WhisperServer(__DIR__ . '/../../Fixtures/fake-whisper-server', '/models/ggml-base.bin');
        $this->servers[] = $server;
        $server->acquire(static function (): void {
        });

        if ($ready) {
            $done = new Deferred();
            $check = Loop::addPeriodicTimer(0.05, function () use ($server, $done) {
                if ($server->isReady()) {
                    $done->resolve(null);
                }
            });
            $deadline = Loop::addTimer(10.0, fn () => $done->resolve(null));
            await($done->promise());
            Loop::cancelTimer($check);
            Loop::cancelTimer($deadline);
            $this->assertTrue($server->isReady(), 'The server should have been ready.');
        }

        return new Transcriber(__DIR__ . '/../../Fixtures/fake-whisper', '/models/ggml-base.bin', $language, $prompt, server: $server);
    }

    private function slowTranscriber(): Transcriber
    {
        return new Transcriber(__DIR__ . '/../../Fixtures/fake-whisper', '/models/ggml-base.bin', 'auto', minimumTimeout: 0.2);
    }
}
