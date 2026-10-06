<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Support\CommandFailedException;
use App\Voice\Speech;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use RuntimeException;
use Tests\RunsOutOfFileDescriptors;
use Tests\WaitsWithin;

use function React\Async\delay;
use function React\Promise\all;

final class SpeechTest extends TestCase
{
    use RunsOutOfFileDescriptors;
    use WaitsWithin;

    private const string FIXTURES = __DIR__ . '/../../Fixtures';

    /** Where the sentences are saved, with Piper's own folder in it. */
    private string $directory;

    private string $voices;

    /** @var list<Speech> Every Piper a test started: one left running would keep the tests from ending. */
    private array $speeches = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/speech-test-' . uniqid();
        mkdir($this->directory);
        // Brackets mean something in a glob pattern, and nothing in a folder's name.
        $this->voices = sys_get_temp_dir() . '/speech-test-voices [' . uniqid() . ']';
        putenv("FAKE_PIPER_LOG={$this->directory}/piper.log");
        putenv("FAKE_PIPER_RUNNING={$this->directory}/piper.running");
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->speeches as $speech) {
                $this->settled($speech->stop());
            }
        } finally {
            // One that is still there would keep the tests from ever ending, without a word about which test left it.
            foreach ($this->pipers() as $pid) {
                posix_kill($pid, SIGKILL);
            }
        }

        unset($_ENV['PIPER_MODEL']);
        putenv('FAKE_PIPER_LOG');
        putenv('FAKE_PIPER_RUNNING');
        putenv('FAKE_PIPER_DELAY');
        putenv('FAKE_PIPER_FAILS_ON');
        putenv('FAKE_PIPER_WARNS_ON');
        exec('rm -rf ' . escapeshellarg($this->directory));

        if (is_dir($this->voices)) {
            array_map(fn (string $file) => unlink("{$this->voices}/{$file}"), array_diff(scandir($this->voices), ['.', '..']));
            rmdir($this->voices);
        }
    }

    public function testAServersVoiceIsInTheSameFolderAsTheOneInEnv(): void
    {
        $_ENV['PIPER_MODEL'] = '/piper/voices/en_US-lessac-medium.onnx';

        $this->assertSame('/piper/voices/en_US-lessac-medium.onnx', Speech::fromEnv()->model);
        $this->assertSame('/piper/voices/pt_BR-faber-medium.onnx', Speech::fromEnv('pt_BR-faber-medium')->model);
    }

    public function testListsTheVoicesInstalledNextToTheOneInEnv(): void
    {
        mkdir($this->voices);

        // Piper keeps each voice's settings in a .onnx.json file next to it.
        foreach (['pt_BR-faber-medium.onnx', 'pt_BR-faber-medium.onnx.json', 'en_US-lessac-medium.onnx', 'en_US-lessac-medium.onnx.json', 'README.txt'] as $file) {
            touch("{$this->voices}/{$file}");
        }

        $_ENV['PIPER_MODEL'] = "{$this->voices}/en_US-lessac-medium.onnx";

        $this->assertSame(['en_US-lessac-medium', 'pt_BR-faber-medium'], Speech::voices());
    }

    public function testListsNoVoicesWhenTheFolderDoesNotExist(): void
    {
        $_ENV['PIPER_MODEL'] = '/nowhere/voices/en_US-lessac-medium.onnx';

        $this->assertSame([], Speech::voices());

        // Nor without a voice in .env: there is no folder to look in.
        unset($_ENV['PIPER_MODEL']);

        $this->assertSame([], Speech::voices());
    }

    public function testStartsPiperBeforeThereIsAnythingToSay(): void
    {
        $speech = $this->speech();
        $this->assertFalse($speech->isRunning());

        $speech->start("{$this->directory}/piper");

        // Piper loads the voice when it starts, which is what a sentence no longer waits for.
        $this->assertTrue($speech->isRunning());
        $this->waitUntil(fn () => count($this->pipers()) === 1);
        $this->assertTrue(posix_kill($this->pipers()[0], 0));
        $this->assertSame(
            "arg=--model\narg=/voices/en_US-lessac-medium.onnx\narg=--output-dir\narg={$this->directory}/piper\n",
            file_get_contents("{$this->directory}/piper.log"),
        );

        // Starting it again does nothing.
        $speech->start("{$this->directory}/elsewhere");
        delay(0.2);
        $this->assertCount(1, $this->pipers());
    }

    public function testSpeaksEverySentenceWithTheSamePiper(): void
    {
        $speech = $this->speech();
        $speech->start("{$this->directory}/piper");

        $first = "{$this->directory}/claude-1.ogg";
        $this->assertSame($first, $this->settled($speech->synthesize('Paris is the capital of France.', $first)));
        $this->assertSame('Paris is the capital of France.', file_get_contents($first), "Piper's file was converted into it.");

        // Piper is still running for the next sentence, whenever it comes.
        delay(0.2);
        $second = "{$this->directory}/claude-2.ogg";
        $this->assertSame($second, $this->settled($speech->synthesize('It has two million people.', $second)));
        $this->assertSame('It has two million people.', file_get_contents($second));

        $this->assertCount(1, $this->pipers(), 'The voice was loaded once.');
        $this->assertTrue($speech->isRunning());

        // Nothing is left of Piper's own files.
        $this->assertSame(['.', '..'], scandir("{$this->directory}/piper"));
        $this->assertSame([], glob("{$this->directory}/*.piper.wav"));
    }

    public function testSpeaksSentencesGivenAtOnceInOrderEachIntoItsOwnFile(): void
    {
        putenv('FAKE_PIPER_DELAY=0.1');
        $speech = $this->speech();
        $speech->start("{$this->directory}/piper");
        $sentences = ['It is a quarter past four.', 'Time for a cup of tea.', 'The kettle is already on.'];

        $paths = $this->settled(all(array_map(
            fn (int $number) => $speech->synthesize($sentences[$number], "{$this->directory}/claude-{$number}.ogg"),
            array_keys($sentences),
        )));

        $this->assertSame($sentences, array_map(file_get_contents(...), $paths));
        $this->assertCount(1, $this->pipers());
    }

    public function testSpeaksATextOfSeveralLinesAsOneSentence(): void
    {
        $speech = $this->speech();
        $speech->start("{$this->directory}/piper");

        // Piper speaks every line it reads into a file of its own: the next sentence would get the second line's.
        $speaking = [
            $speech->synthesize("  Sure.\n\nIt is a quarter\r\npast\tfour. \n", "{$this->directory}/claude-1.ogg"),
            $speech->synthesize('Time for a cup of tea.', "{$this->directory}/claude-2.ogg"),
        ];

        $this->assertSame(
            ['Sure. It is a quarter past four.', 'Time for a cup of tea.'],
            array_map(file_get_contents(...), $this->settled(all($speaking))),
        );
    }

    public function testNeverGivesPiperALineItWouldSkip(): void
    {
        $speech = $this->speech();
        $speech->start("{$this->directory}/piper");

        // Piper says nothing about a line that holds nothing but whitespace, here no-break spaces: given one, it
        // would speak the next sentence into what the bot takes for this one's file, and so on for the rest of the call.
        $nothing = $speech->synthesize("\xC2\xA0\xC2\xA0 \t\xC2\xA0", "{$this->directory}/claude-1.ogg");
        $next = $speech->synthesize('It is a quarter past four.', "{$this->directory}/claude-2.ogg");

        try {
            $this->settled($nothing);
            $this->fail('There was nothing to synthesize.');
        } catch (RuntimeException $e) {
            $this->assertSame('There is nothing to say in the sentence.', $e->getMessage());
        }

        $this->assertSame('It is a quarter past four.', file_get_contents($this->settled($next)), 'The next sentence got its own speech.');
        $this->assertFileDoesNotExist("{$this->directory}/claude-1.ogg");

        // Whatever Python, which Piper is written in, takes for whitespace: an ideographic space, a line or paragraph
        // separator, a next-line or a file separator character, and so on.
        foreach (["\xE3\x80\x80", "\xE2\x80\xA8\xE2\x80\xA9", "\xC2\x85", "\x1C\x1D\x1E\x1F", "\x0B\x0C", '', "\n\r\n", " \xE1\x9A\x80\xE2\x80\x8A\xE2\x80\xAF\xE2\x81\x9F "] as $whitespace) {
            try {
                $this->settled($speech->synthesize($whitespace, "{$this->directory}/claude-3.ogg"));
                $this->fail('There was nothing to synthesize in ' . bin2hex($whitespace) . '.');
            } catch (RuntimeException $e) {
                $this->assertSame('There is nothing to say in the sentence.', $e->getMessage());
            }
        }

        // Inside a sentence, they are the spaces between its words: Piper gets one line. A byte that is no text
        // would end Piper, which can't read it, and becomes a question mark.
        $path = $this->settled($speech->synthesize("Time\xE2\x80\xA8for\x1Ca\xC2\x85cup\xE3\x80\x80of\xC2\xA0tea\xFF.", "{$this->directory}/claude-4.ogg"));

        $this->assertSame('Time for a cup of tea?.', file_get_contents($path));
        $this->assertTrue($speech->isRunning());
        $this->assertCount(1, $this->pipers(), 'Piper never stopped.');
    }

    public function testStartsPiperAgainForTheNextSentenceWhenItStoppedByItself(): void
    {
        putenv('FAKE_PIPER_FAILS_ON=cup of tea');
        $speech = $this->speech();
        $speech->start("{$this->directory}/piper");
        $this->settled($speech->synthesize('It is a quarter past four.', "{$this->directory}/claude-1.ogg"));

        // Piper fails on a sentence, which ends it. The one it got after that is never spoken either.
        $failing = $speech->synthesize('Time for a cup of tea.', "{$this->directory}/claude-2.ogg");
        $waiting = $speech->synthesize('The kettle is already on.', "{$this->directory}/claude-3.ogg");

        foreach ([$failing, $waiting] as $sentence) {
            try {
                $this->settled($sentence);
                $this->fail('Synthesizing should have failed.');
            } catch (CommandFailedException $e) {
                // What Piper said about the files it wrote before is not why it failed.
                $this->assertSame(self::FIXTURES . '/fake-piper exited with code 1: The voice model could not be loaded.', $e->getMessage());
            }
        }

        $this->assertFalse($speech->isRunning());
        $this->assertFileDoesNotExist("{$this->directory}/claude-2.ogg");
        $this->assertFileDoesNotExist("{$this->directory}/claude-3.ogg");
        $this->assertDirectoryDoesNotExist("{$this->directory}/piper", 'The file Piper had started is gone with its folder.');

        // The next sentence starts it again, where it was.
        putenv('FAKE_PIPER_FAILS_ON');
        $path = $this->settled($speech->synthesize('The kettle is already on.', "{$this->directory}/claude-4.ogg"));

        $this->assertSame('The kettle is already on.', file_get_contents($path));
        $this->assertTrue($speech->isRunning());
        $this->assertCount(2, $this->pipers());
        $this->assertStringContainsString("arg=--output-dir\narg={$this->directory}/piper\n", file_get_contents("{$this->directory}/piper.log"));
    }

    public function testWhatPiperSaidOfASentenceItSpokeIsNotWhyALaterOneFails(): void
    {
        // Piper says on stderr what it has no sound for, quoting it, and still speaks the sentence. It keeps
        // running for the whole call, and the logs must never hold what was said in it.
        putenv('FAKE_PIPER_WARNS_ON=Bananas');
        putenv('FAKE_PIPER_FAILS_ON=cup of tea');
        $speech = $this->speech();
        $speech->start("{$this->directory}/piper");
        $this->settled($speech->synthesize('Alice is building a game called Bananas.', "{$this->directory}/claude-1.ogg"));

        try {
            $this->settled($speech->synthesize('Time for a cup of tea.', "{$this->directory}/claude-2.ogg"));
            $this->fail('Synthesizing should have failed.');
        } catch (CommandFailedException $e) {
            $this->assertSame(self::FIXTURES . '/fake-piper exited with code 1: The voice model could not be loaded.', $e->getMessage());
        }
    }

    public function testASentenceFailsWhenPiperCannotBeStarted(): void
    {
        $speech = $this->speech();
        $speech->start("{$this->directory}/piper");
        $this->settled($speech->synthesize('It is a quarter past four.', "{$this->directory}/claude-1.ogg"));
        posix_kill($this->pipers()[0], SIGTERM);
        $this->waitUntil(fn () => ! $speech->isRunning());

        // Piper stopped, and the bot has no file descriptors left to start it again with.
        $failing = $this->withoutFileDescriptors(fn () => $speech->synthesize('Time for a cup of tea.', "{$this->directory}/claude-2.ogg"));

        try {
            $this->settled($failing);
            $this->fail('Synthesizing should have failed.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('Unable to launch a new process: ', $e->getMessage());
        }

        $this->assertFalse($speech->isRunning());

        // The sentence after it starts Piper, now that it can be.
        $path = $this->settled($speech->synthesize('The kettle is already on.', "{$this->directory}/claude-3.ogg"));

        $this->assertSame('The kettle is already on.', file_get_contents($path));
        $this->assertCount(2, $this->pipers());
    }

    public function testEndsPiperOnceItHasSpokenWhatItGot(): void
    {
        putenv('FAKE_PIPER_DELAY=0.3');
        $speech = $this->speech();
        $speech->start("{$this->directory}/piper");
        $this->waitUntil(fn () => count($this->pipers()) === 1);

        // The call ends while Piper is working on a sentence.
        $speaking = $speech->synthesize('It is a quarter past four.', "{$this->directory}/claude-1.ogg");
        $ended = $speech->stop();

        $this->assertSame('It is a quarter past four.', file_get_contents($this->settled($speaking)));

        // Once it has ended, nothing is left of it.
        $this->assertNull($this->settled($ended));
        $this->assertFalse($speech->isRunning());
        $this->assertFalse(posix_kill($this->pipers()[0], 0));
        $this->assertDirectoryDoesNotExist("{$this->directory}/piper");

        // Stopping it again does nothing.
        $this->assertNull($this->settled($speech->stop()));
        $this->assertFalse($speech->isRunning());
    }

    public function testStoppingAPiperThatFailsIsNoFailure(): void
    {
        putenv('FAKE_PIPER_FAILS_ON=cup of tea');
        $speech = $this->speech();
        $speech->start("{$this->directory}/piper");

        $speaking = $speech->synthesize('Time for a cup of tea.', "{$this->directory}/claude-1.ogg");
        $ended = $speech->stop();

        // The sentence fails. Whoever waits for Piper to end only needs to know that it has.
        $this->assertNull($this->settled($ended));
        $this->assertFalse($speech->isRunning());
        $this->assertDirectoryDoesNotExist("{$this->directory}/piper");

        $this->expectException(CommandFailedException::class);
        $this->settled($speaking);
    }

    public function testStopsAPiperThatTakesTooLongOverASentence(): void
    {
        putenv('FAKE_PIPER_DELAY=1.5');
        $speech = $this->speech(timeout: 0.3);
        $speech->start("{$this->directory}/piper");

        try {
            $this->settled($speech->synthesize('It is a quarter past four.', "{$this->directory}/claude-1.ogg"));
            $this->fail('Synthesizing should have timed out.');
        } catch (CommandFailedException $e) {
            $this->assertSame(self::FIXTURES . '/fake-piper timed out after 0.3s', $e->getMessage());
        }

        $this->assertFalse($speech->isRunning());
        $this->assertFileDoesNotExist("{$this->directory}/claude-1.ogg");
    }

    public function testATimeoutThatIsNotNeededStopsNothing(): void
    {
        $speech = $this->speech(timeout: 0.3);
        $speech->start("{$this->directory}/piper");

        $this->settled($speech->synthesize('It is a quarter past four.', "{$this->directory}/claude-1.ogg"));
        delay(0.5);

        $this->assertTrue($speech->isRunning(), 'The sentence was spoken in time.');
        $this->assertSame('Time for a cup of tea.', file_get_contents($this->settled($speech->synthesize('Time for a cup of tea.', "{$this->directory}/claude-2.ogg"))));
        $this->assertCount(1, $this->pipers());
    }

    public function testRejectsWhenPiperEndsWithoutSpeaking(): void
    {
        // A program that ends at once, without a word, as if nothing were wrong.
        $speech = $this->speech(binary: 'true');
        $speech->start("{$this->directory}/piper");

        try {
            $this->settled($speech->synthesize('It is a quarter past four.', "{$this->directory}/claude-1.ogg"));
            $this->fail('Synthesizing should have failed.');
        } catch (CommandFailedException $e) {
            $this->assertSame('true ended before it spoke the sentence', $e->getMessage());
        }

        $this->assertFalse($speech->isRunning());
    }

    public function testRejectsWhenPiperIsNotInstalled(): void
    {
        $speech = $this->speech(binary: '/nowhere/piper');
        $speech->start("{$this->directory}/piper");

        try {
            $this->settled($speech->synthesize('It is a quarter past four.', "{$this->directory}/claude-1.ogg"));
            $this->fail('Synthesizing should have failed.');
        } catch (CommandFailedException $e) {
            $this->assertStringStartsWith('/nowhere/piper exited with code 127: ', $e->getMessage());
            // What the shell says, or setsid where programs are started with it.
            $this->assertMatchesRegularExpression('/not found|No such file/', $e->getMessage());
        }
    }

    public function testRemovesPipersFileWhenItCannotBeConverted(): void
    {
        $speech = $this->speech(ffmpeg: '/nowhere/ffmpeg');
        $speech->start("{$this->directory}/piper");

        try {
            $this->settled($speech->synthesize('Paris is the capital of France.', "{$this->directory}/claude-1.ogg"));
            $this->fail('Synthesizing should have failed.');
        } catch (CommandFailedException $e) {
            $this->assertStringStartsWith('/nowhere/ffmpeg exited with code 127', $e->getMessage());
        }

        $this->assertFileDoesNotExist("{$this->directory}/claude-1.ogg.piper.wav");
        $this->assertFileDoesNotExist("{$this->directory}/claude-1.ogg");
        $this->assertSame(['.', '..'], scandir("{$this->directory}/piper"));

        // Piper had nothing to do with it, and is still running.
        $this->assertTrue($speech->isRunning());
    }

    private function speech(?string $binary = null, ?string $ffmpeg = null, float $timeout = 120.0): Speech
    {
        return $this->speeches[] = new Speech(
            $binary ?? self::FIXTURES . '/fake-piper',
            '/voices/en_US-lessac-medium.onnx',
            $ffmpeg ?? self::FIXTURES . '/fake-ffmpeg',
            $timeout,
        );
    }

    /**
     * Waits for what Piper was asked to do, and fails the test when that takes for ever: a Piper that never
     * speaks a sentence, or never ends, would otherwise keep the tests waiting without a word.
     */
    private function settled(PromiseInterface $promise): mixed
    {
        return $this->within(10.0, $promise, 'Piper');
    }

    /**
     * @return list<int> The process ID of every Piper that was started, oldest first.
     */
    private function pipers(): array
    {
        $running = "{$this->directory}/piper.running";

        return is_file($running) ? array_map(intval(...), file($running, FILE_IGNORE_NEW_LINES)) : [];
    }

    /**
     * Runs the event loop until the condition holds.
     */
    private function waitUntil(callable $condition): void
    {
        for ($i = 0; $i < 200 && ! $condition(); $i++) {
            delay(0.05);
        }

        $this->assertTrue((bool) $condition(), 'Timed out waiting.');
    }
}
