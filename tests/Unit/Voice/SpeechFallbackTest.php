<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Support\CommandFailedException;
use App\Voice\Speech;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;
use Tests\WaitsWithin;

use function React\Async\delay;

/**
 * Kokoro speaks, and Piper takes over for the rest of the call when Kokoro can't: with the real Kokoro program
 * (its stand-in engine, tests/Fixtures/kokoro) and the fake Piper.
 */
final class SpeechFallbackTest extends TestCase
{
    use WaitsWithin;

    private const string FIXTURES = __DIR__ . '/../../Fixtures';

    private string $directory;

    /** @var list<Speech> */
    private array $speeches = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/speech-fallback-test-' . uniqid();
        mkdir($this->directory);
        putenv("FAKE_PIPER_LINES={$this->directory}/piper.lines");
        // The card Kokoro runs on, unless a test says it has none.
        putenv('FAKE_KOKORO_DEVICE=cuda (Stand-in card)');
    }

    protected function tearDown(): void
    {
        foreach ($this->speeches as $speech) {
            $this->settled($speech->stop());
        }

        foreach (['FAKE_PIPER_LINES', 'FAKE_PIPER_LOG', 'FAKE_PIPER_DELAY', 'FAKE_PIPER_FAILS_ON', 'FAKE_KOKORO_DEVICE', 'FAKE_KOKORO_LOAD', 'FAKE_KOKORO_DELAY'] as $variable) {
            putenv($variable);
        }

        unset($_ENV['PIPER_MODEL'], $_ENV['PIPER_BINARY'], $_ENV['KOKORO_VOICE']);
        // phpunit.xml turns Kokoro off for the tests that don't say otherwise.
        $_ENV['KOKORO_BINARY'] = '';
        putenv('KOKORO_BINARY=');
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testSpeaksWithKokoroWhenItIsReady(): void
    {
        $speech = $this->speech();
        $this->watch($speech);
        $this->assertFalse($speech->isReady(), 'Kokoro has not said it is ready before it was started.');

        $speech->start("{$this->directory}/voice");
        $this->waitUntil(fn () => $speech->isReady());

        $path = $this->settled($this->spoken($speech, 'It is a quarter past four.', "{$this->directory}/claude-1.ogg"));

        $this->assertStringStartsWith('RIFF', (string) file_get_contents($path), 'Kokoro wrote it.');
        $this->assertSame([], $this->reasons);
        $this->assertFileDoesNotExist("{$this->directory}/piper.lines", 'Piper was never asked.');
    }

    public function testSpeaksWithPiperWhenKokoroRunsOnTheProcessor(): void
    {
        putenv('FAKE_KOKORO_DEVICE=cpu');
        $speech = $this->speech();
        $this->watch($speech);
        $speech->start("{$this->directory}/voice");

        $path = $this->settled($this->spoken($speech, 'It is a quarter past four.', "{$this->directory}/claude-1.ogg"));

        $this->assertSame('It is a quarter past four.', file_get_contents($path), 'Piper wrote it.');
        $this->assertSame(['it runs on the processor'], $this->reasons);
        $this->assertTrue($speech->isReady(), 'Piper does not load for long.');
    }

    public function testGivesPiperTheSentencesKokoroDidNotSpeakInTheOrderTheyCame(): void
    {
        $speech = $this->speech();
        $this->watch($speech);
        $speech->start("{$this->directory}/voice");
        $this->waitUntil(fn () => $speech->isReady());
        $this->settled($this->spoken($speech, 'It is a quarter past four.', "{$this->directory}/claude-1.ogg"));

        // Kokoro fails on the second, which ends it. The third was given to it after that.
        $paths = $this->settled(\React\Promise\all([
            $this->spoken($speech, 'FAIL, it says.', "{$this->directory}/claude-2.ogg"),
            $this->spoken($speech, 'The kettle is already on.', "{$this->directory}/claude-3.ogg"),
        ]));

        $this->assertSame(['FAIL, it says.', 'The kettle is already on.'], array_map(file_get_contents(...), $paths));
        $this->assertSame(['it ended'], $this->reasons);
        $this->assertSame("FAIL, it says.\nThe kettle is already on.\n", file_get_contents("{$this->directory}/piper.lines"));

        // From then on Piper speaks, and a Kokoro that is not there does not start again.
        $this->assertSame('And a third.', file_get_contents($this->settled($this->spoken($speech, 'And a third.', "{$this->directory}/claude-4.ogg"))));
        $this->assertSame(['it ended'], $this->reasons);
    }

    public function testSpeaksWithPiperWhenKokoroTakesTooLongOverASentence(): void
    {
        putenv('FAKE_KOKORO_DELAY=3');
        $speech = $this->speech(slow: 0.6);
        $this->watch($speech);
        $speech->start("{$this->directory}/voice");
        $this->waitUntil(fn () => $speech->isReady());

        $path = $this->settled($this->spoken($speech, 'It is a quarter past four.', "{$this->directory}/claude-1.ogg"));

        $this->assertSame('It is a quarter past four.', file_get_contents($path));
        $this->assertSame(['a sentence took too long'], $this->reasons);
    }

    public function testSpeaksWithPiperWhenKokoroIsNotReadyInTime(): void
    {
        putenv('FAKE_KOKORO_LOAD=5');
        $speech = $this->speech(load: 0.6);
        $this->watch($speech);
        $speech->start("{$this->directory}/voice");

        // A sentence that comes while it loads waits for it, as long as it is allowed.
        $this->assertFalse($speech->isReady());
        $path = $this->settled($this->spoken($speech, 'It is a quarter past four.', "{$this->directory}/claude-1.ogg"));

        $this->assertSame('It is a quarter past four.', file_get_contents($path));
        $this->assertSame(['it was not ready in time'], $this->reasons);
    }

    public function testASentenceThatCameWhileKokoroLoadedIsSpokenByItOnceItIsReady(): void
    {
        putenv('FAKE_KOKORO_LOAD=1');
        $speech = $this->speech();
        $this->watch($speech);
        $speech->start("{$this->directory}/voice");

        $path = $this->settled($this->spoken($speech, 'It is a quarter past four.', "{$this->directory}/claude-1.ogg"));

        $this->assertStringStartsWith('RIFF', (string) file_get_contents($path));
        $this->assertSame([], $this->reasons);
    }

    public function testToldAfterTheFactWhenKokoroWasGivenUpBeforeAnyoneAsked(): void
    {
        putenv('FAKE_KOKORO_DEVICE=cpu');
        $speech = $this->speech();
        $speech->start("{$this->directory}/voice");
        $this->waitUntil(fn () => $speech->isReady());

        $this->watch($speech);
        $this->assertSame(["it runs on the processor"], $this->reasons);
    }

    public function testAPiperThatFailsAfterTheFallbackIsAnOrdinaryFailure(): void
    {
        putenv('FAKE_KOKORO_DEVICE=cpu');
        putenv('FAKE_PIPER_FAILS_ON=cup of tea');
        $speech = $this->speech();
        $speech->start("{$this->directory}/voice");

        try {
            $this->settled($this->spoken($speech, 'Time for a cup of tea.', "{$this->directory}/claude-1.ogg"));
            $this->fail('Synthesizing should have failed.');
        } catch (CommandFailedException $e) {
            $this->assertStringContainsString('The voice model could not be loaded.', $e->getMessage());
        }

        $this->assertFalse($speech->isRunning());
    }

    public function testStoppingKokoroIsNoFailure(): void
    {
        $speech = $this->speech();
        $this->watch($speech);
        $speech->start("{$this->directory}/voice");
        $this->waitUntil(fn () => $speech->isReady());

        $this->settled($speech->stop());
        delay(0.3);

        $this->assertSame([], $this->reasons, 'The call ended: nothing is given to Piper.');
        $this->assertFileDoesNotExist("{$this->directory}/piper.lines");
    }

    public function testStopsWaitingForAKokoroThatIsNotReadyWhenTheCallEnds(): void
    {
        putenv('FAKE_KOKORO_LOAD=3');
        $speech = $this->speech(load: 0.5);
        $this->watch($speech);
        $speech->start("{$this->directory}/voice");

        $this->settled($speech->stop());
        delay(0.8);

        $this->assertSame([], $this->reasons);
    }

    public function testKokoroOnTheProcessorStaysTheVoiceWhenThereIsNoPiperToTakeOver(): void
    {
        putenv('FAKE_KOKORO_DEVICE=cpu');
        $speech = new Speech(self::FIXTURES . '/fake-kokoro', '/voices/af_heart.onnx', self::FIXTURES . '/fake-ffmpeg', kokoro: true);
        $this->speeches[] = $speech;
        $speech->start("{$this->directory}/voice");

        $path = $this->settled($this->spoken($speech, 'It is a quarter past four.', "{$this->directory}/claude-1.ogg"));

        $this->assertStringStartsWith('RIFF', (string) file_get_contents($path), 'Slow as it is, Kokoro wrote it.');
    }

    public function testWhatAVoiceThatWasGivenUpSaysIsNotTakenForTheVoiceThatSpeaks(): void
    {
        $speech = $this->speech();
        $speech->start("{$this->directory}/voice");
        $wrote = new ReflectionMethod($speech, 'wrote');

        $this->assertTrue($wrote->invoke($speech, 'INFO:__main__:Wrote /nowhere.wav', -1), 'It is taken for nothing.');
        $this->assertFalse($speech->isReady(), 'And it did not make Kokoro ready.');
    }
    public function testPiperGetsItsOwnVoiceAndWhatKokoroLeftInTheFolderIsGone(): void
    {
        putenv('FAKE_KOKORO_DEVICE=cpu');
        putenv("FAKE_PIPER_LOG={$this->directory}/piper.args");
        $speech = $this->speech();
        $this->watch($speech);
        $speech->start("{$this->directory}/voice");
        @mkdir("{$this->directory}/voice");
        file_put_contents("{$this->directory}/voice/left-over.wav", 'Kokoro wrote it');

        $this->settled($this->spoken($speech, 'It is a quarter past four.', "{$this->directory}/claude-1.ogg"));

        $this->assertStringContainsString("arg=/voices/en_US-lessac-medium.onnx\n", (string) file_get_contents("{$this->directory}/piper.args"), 'Piper has the voice of its own, not Kokoro\'s.');
        $this->assertFileDoesNotExist("{$this->directory}/voice/left-over.wav");
        $this->assertNull($this->property($speech, 'loading'), 'Nothing waits for Kokoro to load any more.');
    }

    public function testASentenceGivenToPiperHasTheTimeAPiperSentenceHas(): void
    {
        putenv('FAKE_PIPER_DELAY=5');
        $speech = $this->speech(timeout: 0.5);
        $speech->start("{$this->directory}/voice");
        $this->waitUntil(fn () => $speech->isReady());

        try {
            // Kokoro fails on it, and Piper is slow.
            $this->settled($this->spoken($speech, 'FAIL, it says.', "{$this->directory}/claude-1.ogg"));
            $this->fail('Synthesizing should have failed.');
        } catch (CommandFailedException $e) {
            $this->assertStringContainsString('timed out after 0.5s', $e->getMessage());
        }
    }

    public function testASentenceThatWaitsForKokoroToLoadHasAsLongAsItMayLoad(): void
    {
        putenv('FAKE_KOKORO_LOAD=2');
        $speech = $this->speech(slow: 0.5);
        $this->watch($speech);
        $speech->start("{$this->directory}/voice");

        $path = $this->settled($this->spoken($speech, 'It is a quarter past four.', "{$this->directory}/claude-1.ogg"));

        $this->assertStringStartsWith('RIFF', (string) file_get_contents($path), 'Kokoro spoke it, 1.5 s after a sentence may take.');
        $this->assertSame([], $this->reasons);
    }

    public function testKokoroIsNotGivenUpForNotLoadingOnceItHas(): void
    {
        $speech = $this->speech(load: 0.6);
        $this->watch($speech);
        $speech->start("{$this->directory}/voice");
        $this->waitUntil(fn () => $speech->isReady());

        delay(1.0);

        $this->assertSame([], $this->reasons);
        $this->assertNull($this->property($speech, 'loading'));
    }

    public function testASentenceAKokoroThatEndedWithTheCallHadNotSpokenFailsAtOnce(): void
    {
        putenv('FAKE_KOKORO_DELAY=5');
        $speech = $this->speech();
        $this->watch($speech);
        $speech->start("{$this->directory}/voice");
        $this->waitUntil(fn () => $speech->isReady());
        $sentence = $this->spoken($speech, 'It is a quarter past four.', "{$this->directory}/claude-1.ogg");
        $failed = null;
        $sentence->then(null, function (Throwable $e) use (&$failed): void {
            $failed = $e;
        });

        // The call ends, and Kokoro with it before it has spoken the sentence.
        $stopped = $speech->stop();
        $this->property($speech, 'piper')->stop('killed');
        $this->settled($stopped);
        delay(0.3);

        $this->assertInstanceOf(CommandFailedException::class, $failed, 'It is not left waiting for a voice that is gone.');
        $this->assertSame([], $this->reasons, 'The call ended: nothing is given to Piper.');
    }

    public function testWhatKokoroSaysAfterTheCallEndedStartsNothing(): void
    {
        $speech = $this->speech();
        $this->watch($speech);
        $speech->start("{$this->directory}/voice");
        $this->waitUntil(fn () => $speech->isReady());
        $this->settled($speech->stop());

        (new ReflectionMethod($speech, 'wrote'))->invoke($speech, 'DEVICE cpu', $this->property($speech, 'generation'));

        $this->assertSame([], $this->reasons);
        $this->assertFalse($speech->isRunning(), 'Piper was not started for a call that is over.');
    }

    public function testWhatAProgramThatWasGivenUpSaysIsNotTakenForTheOneThatSpeaks(): void
    {
        putenv('FAKE_KOKORO_DEVICE=cpu');
        putenv('FAKE_PIPER_DELAY=1');
        $speech = $this->speech();
        $this->watch($speech);
        $speech->start("{$this->directory}/voice");
        $kokoro = $this->property($speech, 'generation');
        $this->waitUntil(fn () => $speech->isReady());
        $path = "{$this->directory}/claude-1.ogg";
        $sentence = $speech->synthesize('It is a quarter past four.', $path);

        // Kokoro, which was stopped, still has a "Wrote" to say.
        $this->assertTrue((new ReflectionMethod($speech, 'wrote'))->invoke($speech, 'INFO:__main__:Wrote /nowhere.wav', $kokoro));

        $this->settled($sentence->whole());
        $this->assertSame('It is a quarter past four.', file_get_contents($path), 'Piper spoke it.');
    }

    public function testPipersOwnLinesAreNotKokorosMessages(): void
    {
        $speech = new Speech(self::FIXTURES . '/fake-piper', '/voices/en_US-lessac-medium.onnx', self::FIXTURES . '/fake-ffmpeg');

        $this->assertFalse((new ReflectionMethod($speech, 'wrote'))->invoke($speech, 'READY {}', $this->property($speech, 'generation')), 'It may say why the voice fails.');
        $this->assertTrue($speech->isReady(), 'And it is not waiting for a READY.');
    }

    public function testKokoroFallsBackToPiperOnlyWhenPiperIsInstalled(): void
    {
        $voices = $this->kokoroFolder(['af_heart']);
        mkdir("{$this->directory}/piper");
        touch("{$this->directory}/piper/voice.onnx");
        $_ENV['KOKORO_BINARY'] = "{$voices}/kokoro";
        $_ENV['PIPER_BINARY'] = self::FIXTURES . '/fake-piper';
        $_ENV['PIPER_MODEL'] = "{$this->directory}/piper/voice.onnx";

        $both = Speech::fromEnv();
        $this->assertSame(self::FIXTURES . '/fake-piper', $this->property($both, 'fallbackBinary'));
        $this->assertSame("{$this->directory}/piper/voice.onnx", $this->property($both, 'fallbackModel'));

        $_ENV['PIPER_BINARY'] = '/nowhere/piper';
        $this->assertNull($this->property(Speech::fromEnv(), 'fallbackBinary'), 'There is no Piper.');

        $_ENV['PIPER_BINARY'] = self::FIXTURES . '/fake-piper';
        $_ENV['PIPER_MODEL'] = "{$this->directory}/piper/nowhere.onnx";
        $this->assertNull($this->property(Speech::fromEnv(), 'fallbackBinary'), 'There is no voice for Piper.');
    }

    public function testAVoiceWithoutAFallbackIsReadyAtOnce(): void
    {
        $speech = new Speech(self::FIXTURES . '/fake-piper', '/voices/en_US-lessac-medium.onnx', self::FIXTURES . '/fake-ffmpeg');

        $this->assertTrue($speech->isReady());
    }

    public function testKokoroIsTheDefaultVoiceWhenItIsInstalledAndPiperIsItsFallback(): void
    {
        $voices = $this->kokoroFolder(['af_heart', 'af_bella']);
        $_ENV['KOKORO_BINARY'] = "{$voices}/kokoro";
        $_ENV['PIPER_BINARY'] = self::FIXTURES . '/fake-piper';
        $_ENV['PIPER_MODEL'] = "{$this->directory}/piper/en_US-lessac-medium.onnx";
        mkdir("{$this->directory}/piper");
        touch($_ENV['PIPER_MODEL']);

        $default = Speech::fromEnv();
        $this->assertSame("{$voices}/kokoro", $default->binary);
        $this->assertSame("{$voices}/voices/af_heart.onnx", $default->model);
        $this->assertFalse($default->isReady());

        // A server's voice of Kokoro's is Kokoro's; any other is Piper's, which has no fallback.
        $this->assertSame("{$voices}/voices/af_bella.onnx", Speech::fromEnv('af_bella')->model);
        $piper = Speech::fromEnv('pt_BR-faber-medium');
        $this->assertSame(self::FIXTURES . '/fake-piper', $piper->binary);
        $this->assertSame("{$this->directory}/piper/pt_BR-faber-medium.onnx", $piper->model);
        $this->assertTrue($piper->isReady());

        // KOKORO_VOICE is the default, when it is installed.
        $_ENV['KOKORO_VOICE'] = 'af_bella';
        $this->assertSame("{$voices}/voices/af_bella.onnx", Speech::fromEnv()->model);
        $_ENV['KOKORO_VOICE'] = 'af_nicole';
        $this->assertSame("{$this->directory}/piper/en_US-lessac-medium.onnx", Speech::fromEnv()->model, 'Piper speaks when the default voice of Kokoro is not installed.');
    }

    public function testKokoroHasNoFallbackWhenPiperIsNotInstalled(): void
    {
        $voices = $this->kokoroFolder(['af_heart']);
        $_ENV['KOKORO_BINARY'] = "{$voices}/kokoro";
        $_ENV['PIPER_BINARY'] = self::FIXTURES . '/fake-piper';
        // No such file: Piper is not installed.
        $_ENV['PIPER_MODEL'] = "{$this->directory}/nowhere/en_US-lessac-medium.onnx";

        $speech = Speech::fromEnv();

        $this->assertSame("{$voices}/voices/af_heart.onnx", $speech->model);
        $this->assertFalse($speech->isReady(), 'Kokoro is all there is to wait for, as it cannot fall back.');
    }

    public function testListsKokorosVoicesBeforePipersWithoutRepeatingOne(): void
    {
        $voices = $this->kokoroFolder(['af_heart', 'bf_emma']);
        mkdir("{$this->directory}/piper");
        touch("{$this->directory}/piper/en_US-lessac-medium.onnx");
        touch("{$this->directory}/piper/af_heart.onnx");
        $_ENV['KOKORO_BINARY'] = "{$voices}/kokoro";
        $_ENV['PIPER_MODEL'] = "{$this->directory}/piper/en_US-lessac-medium.onnx";

        $this->assertSame(['af_heart', 'bf_emma', 'en_US-lessac-medium'], Speech::voices());
    }

    public function testKokoroIsFoundByItselfWhereTheRepositoryHasItInstalledAndOffWhenKokoroBinaryIsEmpty(): void
    {
        $_ENV['PIPER_MODEL'] = "{$this->directory}/piper/en_US-lessac-medium.onnx";
        $_ENV['KOKORO_BINARY'] = '';

        $this->assertSame([], Speech::voices(), 'An empty KOKORO_BINARY turns it off.');
        $this->assertSame("{$this->directory}/piper/en_US-lessac-medium.onnx", Speech::fromEnv()->model);

        // Unset: this repository's own, when it has been installed.
        unset($_ENV['KOKORO_BINARY']);
        putenv('KOKORO_BINARY');
        $installed = is_file(dirname(__DIR__, 3) . '/kokoro/venv/bin/python');

        $this->assertSame($installed, str_ends_with(Speech::fromEnv()->binary, '/kokoro/kokoro') && is_file(Speech::fromEnv()->model));
    }

    /**
     * @param list<string> $names
     *
     * @return string A folder like kokoro/: the program, and an empty file for each voice in voices/.
     */
    private function kokoroFolder(array $names): string
    {
        $folder = "{$this->directory}/kokoro";
        mkdir("{$folder}/voices", 0777, true);
        copy(self::FIXTURES . '/fake-kokoro', "{$folder}/kokoro");
        chmod("{$folder}/kokoro", 0755);

        foreach ($names as $name) {
            touch("{$folder}/voices/{$name}.onnx");
        }

        return $folder;
    }

    /** @var list<string> What the speech told, as it was told. */
    private array $reasons = [];

    private function watch(Speech $speech): void
    {
        $speech->whenFallingBack(function (string $reason): void {
            $this->reasons[] = $reason;
        });
    }

    private function property(Speech $speech, string $name): mixed
    {
        return (new ReflectionProperty($speech, $name))->getValue($speech);
    }

    private function speech(float $load = 30.0, float $slow = 5.0, float $timeout = 120.0): Speech
    {
        return $this->speeches[] = new Speech(
            self::FIXTURES . '/fake-kokoro',
            '/voices/af_heart.onnx',
            self::FIXTURES . '/fake-ffmpeg',
            timeout: $timeout,
            fallbackBinary: self::FIXTURES . '/fake-piper',
            fallbackModel: '/voices/en_US-lessac-medium.onnx',
            loadSeconds: $load,
            slowSeconds: $slow,
            kokoro: true,
        );
    }

    /**
     * @return PromiseInterface<string> The path of the sentence's file, once it is complete.
     */
    private function spoken(Speech $speech, string $text, string $path): PromiseInterface
    {
        $sentence = $speech->synthesize($text, $path);

        return $sentence->whole()->then(static fn () => $sentence->path);
    }

    private function settled(PromiseInterface $promise): mixed
    {
        return $this->within(15.0, $promise, 'the voice');
    }

    private function waitUntil(callable $condition): void
    {
        for ($i = 0; $i < 200 && ! $condition(); $i++) {
            delay(0.05);
        }

        $this->assertTrue((bool) $condition(), 'Timed out waiting.');
    }
}
