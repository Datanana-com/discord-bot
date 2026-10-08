<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Voice\Sentence;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use Tests\Ogg;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;
use function React\Promise\set_rejection_handler;

final class SentenceTest extends TestCase
{
    private const string NOT_OPUS = 'Not an Ogg Opus file: it does not start with the OpusHead and OpusTags headers.';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sentence-test-' . uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob("{$this->directory}/*") ?: []);
        rmdir($this->directory);
    }

    public function testCanBePlayedFromTheFirstOfItsStreamAndIsWrittenOnceItIsWhole(): void
    {
        $voiced = new Deferred();
        $sentence = new Sentence($path = "{$this->directory}/claude-1.ogg", $voiced->promise());
        $stream = Ogg::opus(['one', 'two', 'three'], perPage: 1);

        // The voice is still speaking it: there is nothing of it yet.
        $this->assertSame([false, false, false], [$this->settled($sentence->voiced()), $this->settled($sentence->started()), $this->settled($sentence->whole())]);
        $this->assertSame([], $sentence->packets());
        $this->assertFalse($sentence->isOver());

        // The voice is free for the next sentence, and this one's speech is with the encoder.
        $voiced->resolve(null);
        $this->assertSame([true, false, false], [$this->settled($sentence->voiced()), $this->settled($sentence->started()), $this->settled($sentence->whole())]);

        // The headers and the first packet have come: from here on it can be played.
        $firstPages = strlen($stream) - strlen(Ogg::page(['two'], 3)) - strlen(Ogg::page(['three'], 4, Ogg::LAST));
        $sentence->write(substr($stream, 0, $firstPages));
        $this->assertSame([true, false], [$this->settled($sentence->started()), $this->settled($sentence->whole())]);
        $this->assertSame(['one'], $sentence->packets());
        $this->assertFileDoesNotExist($path, 'The file is written once, when it is whole: nobody may play the start of one.');

        // The rest comes in pieces that end anywhere.
        $rest = substr($stream, $firstPages);
        $sentence->write(substr($rest, 0, 10));
        $this->assertSame(['one'], $sentence->packets());
        $sentence->write(substr($rest, 10));
        $this->assertSame(['one', 'two', 'three'], $sentence->packets());
        $this->assertFalse($sentence->isOver(), 'The encoder has not ended yet.');

        $sentence->end();
        $this->assertTrue($this->settled($sentence->whole()));
        $this->assertTrue($sentence->isOver());
        $this->assertNull($sentence->failure());
        $this->assertSame($stream, file_get_contents($path));
        $this->assertSame(['one', 'two', 'three'], $sentence->packets());

        // Nothing changes it any more.
        $sentence->write('more');
        $sentence->fail(new RuntimeException('too late'));
        $sentence->end();
        $this->assertSame($stream, file_get_contents($path));
        $this->assertNull($sentence->failure());
    }

    public function testTellsWhoWatchesItWheneverMoreCameAndWhenItIsOver(): void
    {
        $sentence = new Sentence("{$this->directory}/claude-1.ogg", resolve(null));
        $told = [0, 0];
        $sentence->watch(function () use (&$told, $sentence) {
            $told[0]++;
            // Whoever is told may ask at once: what came is there, and so is whether it is over.
            $told[1] = count($sentence->packets()) . ($sentence->isOver() ? ' over' : '');
        });

        $sentence->write(Ogg::opus(['one'], perPage: 1));
        $this->assertSame([1, '1'], $told);

        // Nothing came: nobody is told.
        $sentence->write('');
        $this->assertSame([1, '1'], $told);

        $sentence->end();
        $this->assertSame([2, '1 over'], $told);

        // Nobody is kept waiting for what is over.
        $late = false;
        $sentence->watch(function () use (&$late) {
            $late = true;
        });
        $sentence->write('more');
        $sentence->end();
        $this->assertFalse($late);
        $this->assertSame(2, $told[0]);
    }

    public function testFailsWithWhatCameOfItSoFar(): void
    {
        $sentence = new Sentence($path = "{$this->directory}/claude-1.ogg", resolve(null));
        $told = 0;
        $sentence->watch(function () use (&$told) {
            $told++;
        });
        $sentence->write(Ogg::opus(['one'], perPage: 1));

        // The encoder ended in the middle of it.
        $sentence->fail($failure = new RuntimeException('ffmpeg exited with code 1'));

        $this->assertSame(2, $told);
        $this->assertTrue($sentence->isOver());
        $this->assertSame($failure, $sentence->failure());
        $this->assertSame(['one'], $sentence->packets(), 'What came of it is still there to play.');
        $this->assertTrue($this->settled($sentence->started()), 'It had started.');
        $this->assertSame($failure, $this->settled($sentence->whole()));
        $this->assertFileDoesNotExist($path, 'Half a sentence is not kept.');

        // Nothing changes it any more: not what the encoder still had to write, either.
        $sentence->write(Ogg::page(['two'], 3));
        $sentence->end();
        $sentence->fail(new RuntimeException('again'));
        $this->assertSame(['one'], $sentence->packets());
        $this->assertSame(2, $told);
        $this->assertSame($failure, $sentence->failure());
        $this->assertFileDoesNotExist($path);
    }

    public function testAFailureNobodyWaitsForIsNotReported(): void
    {
        $unhandled = [];
        $previous = set_rejection_handler(function (Throwable $e) use (&$unhandled) {
            $unhandled[] = $e->getMessage();
        });

        try {
            // A sentence of an answer that was cut off: nobody waits for the voice, for its first packet or for its file.
            $failure = new RuntimeException('piper exited with code 1');
            $sentence = new Sentence("{$this->directory}/claude-1.ogg", reject($failure));
            $sentence->fail($failure);
            unset($sentence);
            gc_collect_cycles();
        } finally {
            set_rejection_handler($previous);
        }

        $this->assertSame([], $unhandled);
    }

    public function testFailsBeforeAnythingCameWhenTheVoiceDidNotSpeakIt(): void
    {
        $sentence = new Sentence("{$this->directory}/claude-1.ogg", resolve(null));

        $sentence->fail($failure = new RuntimeException('piper exited with code 1'));

        $this->assertSame($failure, $this->settled($sentence->started()));
        $this->assertSame($failure, $this->settled($sentence->whole()));
        $this->assertSame([], $sentence->packets(), 'Nothing of it, which is no reason of its own to fail.');
    }

    public function testASentenceNobodyWantsIsNotWritten(): void
    {
        $sentence = new Sentence($path = "{$this->directory}/claude-1.ogg", resolve(null));
        $sentence->write($stream = Ogg::opus(['one']));

        // They opted out while it was encoded.
        $sentence->drop();
        $sentence->end();

        $this->assertTrue($this->settled($sentence->whole()), 'It is whole all the same, for whoever waits for the encoder.');
        $this->assertFileDoesNotExist($path);

        // And one that was already written is deleted.
        $written = new Sentence($other = "{$this->directory}/claude-2.ogg", resolve(null));
        $written->write($stream);
        $written->end();
        $this->assertFileExists($other);

        $written->drop();
        $this->assertFileDoesNotExist($other);

        // Dropping it again finds nothing to delete.
        $written->drop();
        $this->assertFileDoesNotExist($other);
    }

    public function testAStreamOfNothingIsOverAllTheSameButIsNoOpus(): void
    {
        $sentence = new Sentence($path = "{$this->directory}/claude-1.ogg", resolve(null));

        // No encoder ends without a word and without failing, but whoever waits for the first of it must not wait for ever.
        $sentence->end();

        $this->assertTrue($this->settled($sentence->started()));
        $this->assertTrue($this->settled($sentence->whole()));
        $this->assertSame('', file_get_contents($path));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(self::NOT_OPUS);
        $sentence->packets();
    }

    public function testSaysThatItsStreamIsNotOpusAsSoonAsThatIsKnown(): void
    {
        $sentence = new Sentence("{$this->directory}/claude-1.ogg", resolve(null));
        $sentence->write('RIFF');

        try {
            $sentence->packets();
            $this->fail('A WAV file was taken for Ogg Opus.');
        } catch (RuntimeException $e) {
            $this->assertSame(self::NOT_OPUS, $e->getMessage());
        }

        // And again once it is whole, for whoever asks then.
        $sentence->end();
        $this->expectExceptionMessage(self::NOT_OPUS);
        $sentence->packets();
    }

    /**
     * @return bool|Throwable False while the promise is pending, true once it resolved, and why once it rejected.
     */
    private function settled(PromiseInterface $promise): bool|Throwable
    {
        $settled = false;
        $promise->then(function () use (&$settled) {
            $settled = true;
        }, function (Throwable $e) use (&$settled) {
            $settled = $e;
        });

        return $settled;
    }
}
