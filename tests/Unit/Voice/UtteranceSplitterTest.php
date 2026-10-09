<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Voice\UtteranceSplitter;
use PHPUnit\Framework\TestCase;

final class UtteranceSplitterTest extends TestCase
{
    /** One 20 ms Opus frame decoded to 48 kHz, 16-bit stereo PCM. */
    private const int FRAME_BYTES = 3840;

    private string $directory;

    /** @var list<array{string, string, float}> */
    private array $utterances = [];

    private UtteranceSplitter $splitter;

    /** @var list<array{string, float, int}> */
    private array $gaps = [];

    /** @var list<array{string, string, float}> What the early splitter handed out: who, the copy, how long. */
    private array $copies = [];

    /** @var list<string> Who went on after the early splitter handed out a copy. */
    private array $drops = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/splitter-test-' . uniqid();
        $this->splitter = new UtteranceSplitter($this->directory, function (string $userId, string $wavPath, float $seconds) {
            $this->utterances[] = [$userId, $wavPath, $seconds];
        }, onGaps: function (string $userId, float $longestGap, int $longGaps) {
            $this->gaps[] = [$userId, $longestGap, $longGaps];
        });
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob("{$this->directory}/*"));
        @rmdir($this->directory);
    }

    public function testFinishesAnUtteranceAfterASilence(): void
    {
        $this->speak('alice', from: 0.0, seconds: 1.0);

        // Her last audio arrived at 0.98 s.
        $this->splitter->flushSilent(1.55);
        $this->assertSame([], $this->utterances, 'Half a second of silence is just a pause.');

        $this->splitter->flushSilent(1.6);
        $this->assertCount(1, $this->utterances, 'After 0.6 seconds, she is done.');

        [$userId, $wavPath, $seconds] = $this->utterances[0];
        $this->assertSame('alice', $userId);
        $this->assertSame(1.0, $seconds);
        $this->assertValidWav($wavPath, seconds: 1.0);
    }

    public function testWaitsAsLongAsItIsToldToForSomeoneWhoPauses(): void
    {
        $splitter = new UtteranceSplitter($this->directory, function (string $userId, string $wavPath, float $seconds) {
            $this->utterances[] = [$userId, $wavPath, $seconds];
        }, silenceSeconds: 1.5);

        // Alice stops for a second in the middle of her sentence, then goes on.
        $this->speak('alice', from: 0.0, seconds: 1.0, splitter: $splitter);
        $splitter->flushSilent(2.0);
        $this->assertSame([], $this->utterances);

        $this->speak('alice', from: 2.0, seconds: 1.0, splitter: $splitter);
        $splitter->flushSilent(4.4);
        $this->assertSame([], $this->utterances);

        $splitter->flushSilent(4.5);
        $this->assertCount(1, $this->utterances);
        $this->assertSame(2.0, $this->utterances[0][2], 'It is one utterance.');
    }

    public function testKeepsEachSpeakerSeparate(): void
    {
        $this->speak('alice', from: 0.0, seconds: 1.0);
        $this->speak('bob', from: 0.5, seconds: 2.0);

        $this->splitter->flushSilent(2.0);
        $this->assertSame(['alice'], array_column($this->utterances, 0));

        $this->splitter->flushSilent(3.5);
        $this->assertSame(['alice', 'bob'], array_column($this->utterances, 0));
        $this->assertValidWav($this->utterances[1][1], seconds: 2.0);
    }

    public function testDropsUtterancesThatAreTooShort(): void
    {
        $this->speak('alice', from: 0.0, seconds: 0.2);

        $this->splitter->flushSilent(5.0);

        $this->assertSame([], $this->utterances);
        $this->assertSame([], glob("{$this->directory}/*.wav"), 'The short recording is deleted.');
    }

    public function testCutsUtterancesThatAreTooLong(): void
    {
        $this->speak('alice', from: 0.0, seconds: 31.0);

        $this->assertCount(1, $this->utterances, 'The first 30 seconds are handled without waiting for a pause.');
        $this->assertValidWav($this->utterances[0][1], seconds: 30.0);

        $this->splitter->flushSilent(40.0);
        $this->assertCount(2, $this->utterances);
        $this->assertValidWav($this->utterances[1][1], seconds: 1.0);
    }

    public function testFlushAllFinishesEveryUtteranceInProgress(): void
    {
        $this->speak('alice', from: 0.0, seconds: 1.0);
        $this->speak('bob', from: 0.0, seconds: 1.0);

        $this->splitter->flushAll();

        $this->assertSame(['alice', 'bob'], array_column($this->utterances, 0));
    }

    public function testReportsTheLongestGapInsideAnUtteranceAndHowManyWereLong(): void
    {
        // Gaps of 0.32, 0.27 and 0.52 s between four bursts of speech (the last packet of a burst is 20 ms before its end).
        $this->speak('alice', from: 0.0, seconds: 0.2);
        $this->speak('alice', from: 0.5, seconds: 0.2);
        $this->speak('alice', from: 0.95, seconds: 0.2);
        $this->speak('alice', from: 1.65, seconds: 0.2);
        $this->splitter->flushSilent(3.0);

        $this->assertCount(1, $this->gaps);
        [$userId, $longestGap, $longGaps] = $this->gaps[0];
        $this->assertSame('alice', $userId);
        $this->assertEqualsWithDelta(0.52, $longestGap, 0.001);
        $this->assertSame(3, $longGaps);
    }

    public function testCountsAGapOfExactlyTheLongGapAndNotOneThatIsShorter(): void
    {
        $this->splitter->push('alice', str_repeat("\x01\x00", self::FRAME_BYTES / 2), 0.0);
        // Exactly 0.2 s after the packet before it, as a double.
        $this->speak('alice', from: 0.2, seconds: 0.5);
        $this->splitter->flushSilent(3.0);
        $this->assertSame(1, $this->gaps[0][2], 'A gap of 0.2 s counts.');
        $this->assertSame(0.2, $this->gaps[0][1]);

        $this->gaps = [];
        $this->speak('bob', from: 0.0, seconds: 0.2);
        $this->speak('bob', from: 0.37, seconds: 0.5);
        $this->splitter->flushSilent(5.0);
        $this->assertSame(0, $this->gaps[0][2], 'A gap of 0.19 s is a pause that does not count.');
        $this->assertEqualsWithDelta(0.19, $this->gaps[0][1], 0.001);
    }

    public function testASpeakerWhoNeverPausesHasNoLongGaps(): void
    {
        $this->speak('alice', from: 0.0, seconds: 1.0);
        $this->splitter->flushSilent(3.0);

        $this->assertCount(1, $this->gaps);
        $this->assertEqualsWithDelta(0.02, $this->gaps[0][1], 0.001, 'Packets come 20 ms apart.');
        $this->assertSame(0, $this->gaps[0][2]);
    }

    public function testEachUtteranceStartsAgainWithoutGaps(): void
    {
        $this->speak('alice', from: 0.0, seconds: 0.2);
        $this->speak('alice', from: 0.5, seconds: 0.5);
        $this->splitter->flushSilent(3.0);
        $this->speak('alice', from: 5.0, seconds: 1.0);
        $this->speak('bob', from: 5.0, seconds: 1.0);
        $this->splitter->flushSilent(8.0);

        $this->assertEqualsWithDelta(0.32, $this->gaps[0][1], 0.001);
        $this->assertSame(1, $this->gaps[0][2]);
        $this->assertSame([['alice', 0], ['bob', 0]], array_map(fn (array $gaps) => [$gaps[0], $gaps[2]], array_slice($this->gaps, 1)), 'The wait before a new utterance is not a gap inside it.');
    }

    public function testReportsTheGapsAfterTheUtteranceAndNotAtAllForOneThatIsDropped(): void
    {
        $order = [];
        $splitter = new UtteranceSplitter(
            $this->directory,
            function () use (&$order) {
                $order[] = 'utterance';
            },
            onGaps: function () use (&$order) {
                $order[] = 'gaps';
            },
        );
        $this->speak('alice', from: 0.0, seconds: 1.0, splitter: $splitter);
        $this->speak('bob', from: 0.0, seconds: 0.2, splitter: $splitter);
        $splitter->flushAll();

        $this->assertSame(['utterance', 'gaps'], $order, "Bob's 0.2 s was dropped, and so were its gaps.");
    }

    public function testReportsTheGapsOfAnUtteranceThatWasCutAtTheLongestLength(): void
    {
        $this->speak('alice', from: 0.0, seconds: 10.0);
        $this->speak('alice', from: 10.5, seconds: 21.0);

        $this->assertCount(1, $this->gaps, 'The first 30 seconds are over, and report their gaps.');
        $this->assertSame(1, $this->gaps[0][2]);
        $this->assertEqualsWithDelta(0.52, $this->gaps[0][1], 0.001);
    }

    public function testHandsOutACopyOfWhatWasSaidOnceTheLastOfTheSilenceBegins(): void
    {
        $splitter = $this->earlySplitter();
        $this->speak('alice', from: 0.0, seconds: 1.0, splitter: $splitter);

        // Her last audio arrived at 0.98 s.
        $splitter->flushSilent(1.27);
        $this->assertSame([], $this->copies, '0.29 s of silence is not yet 0.3 s.');

        $splitter->flushSilent(1.29);
        $this->assertCount(1, $this->copies);
        [$userId, $copyPath, $seconds] = $this->copies[0];
        $this->assertSame('alice', $userId);
        $this->assertSame(1.0, $seconds);
        $this->assertValidWav($copyPath, seconds: 1.0);
        $this->assertSame([], $this->utterances, 'She is not done: the recording goes on.');

        $splitter->flushSilent(1.5);
        $this->assertCount(1, $this->copies, 'Once for each silence.');

        $splitter->flushSilent(1.6);
        $this->assertCount(1, $this->utterances);
        $this->assertSame(file_get_contents($this->utterances[0][1]), file_get_contents($copyPath), 'It is what the utterance is, byte for byte.');
        $this->assertSame([], $this->drops);
    }

    public function testTheCopyIsAFileOfItsOwnThatTheCallerDeletes(): void
    {
        $splitter = $this->earlySplitter();
        $this->speak('alice', from: 0.0, seconds: 1.0, splitter: $splitter);
        $splitter->flushSilent(1.3);

        $this->assertNotSame($this->copies[0][1], glob("{$this->directory}/utterance-*.wav")[0]);
        unlink($this->copies[0][1]);
        $splitter->flushSilent(1.6);
        $this->assertValidWav($this->utterances[0][1], seconds: 1.0);
    }

    public function testTheCopyIsDroppedWhenSheGoesOnAndAnotherIsHandedOutAtTheNextSilence(): void
    {
        $splitter = $this->earlySplitter();
        $this->speak('alice', from: 0.0, seconds: 1.0, splitter: $splitter);
        $splitter->flushSilent(1.3);
        $this->assertSame([], $this->drops);

        // She goes on after 0.34 s.
        $this->speak('alice', from: 1.32, seconds: 1.0, splitter: $splitter);
        $this->assertSame(['alice'], $this->drops, 'Told once, with her first packet.');
        $this->assertCount(1, $this->copies);

        // Her last audio arrived at 2.30 s.
        $splitter->flushSilent(2.5);
        $this->assertCount(1, $this->copies, '0.2 s of silence.');
        $splitter->flushSilent(2.63);
        $this->assertCount(2, $this->copies, 'The next silence starts another.');
        $this->assertNotSame($this->copies[0][1], $this->copies[1][1], 'Another file: the first may still be read.');
        $this->assertEqualsWithDelta(2.0, $this->copies[1][2], 0.001);

        $splitter->flushSilent(3.0);
        $this->assertCount(1, $this->utterances);
        $this->assertSame(['alice'], $this->drops);
        $this->assertSame(file_get_contents($this->utterances[0][1]), file_get_contents($this->copies[1][1]));
    }

    public function testNothingIsHandedOutForWhatIsTooShortToBeKept(): void
    {
        $splitter = $this->earlySplitter();
        $this->speak('alice', from: 0.0, seconds: 0.48, splitter: $splitter);

        $splitter->flushSilent(1.0);
        $this->assertSame([], $this->copies);
        $this->assertSame([], $this->utterances);
        $this->assertSame([], $this->drops);

        // Just long enough: 0.5 s of audio.
        $this->speak('bob', from: 2.0, seconds: 0.5, splitter: $splitter);
        $splitter->flushSilent(2.8);
        $this->assertSame(['bob'], array_column($this->copies, 0));
    }

    public function testASentenceThatIsCutAtTheLongestLengthHasNoCopyToUse(): void
    {
        $splitter = $this->earlySplitter();
        $this->speak('alice', from: 0.0, seconds: 29.0, splitter: $splitter);
        $splitter->flushSilent(29.4);
        $this->assertCount(1, $this->copies);

        $this->speak('alice', from: 29.42, seconds: 2.0, splitter: $splitter);

        $this->assertSame(['alice'], $this->drops, 'She went on: the copy is dropped before the cut.');
        $this->assertCount(1, $this->utterances);
        $this->assertValidWav($this->utterances[0][1], seconds: 30.0);
        $this->assertNotSame(file_get_contents($this->copies[0][1]), file_get_contents($this->utterances[0][1]));
    }

    public function testStartsAtTheLastOfTheSilenceThatEndsAnUtteranceWhateverItsLength(): void
    {
        $splitter = $this->earlySplitter(silenceSeconds: 1.5);
        $this->speak('alice', from: 0.0, seconds: 1.0, splitter: $splitter);

        $splitter->flushSilent(2.15);
        $this->assertSame([], $this->copies, '1.17 s of silence, and the last 0.3 s of 1.5 s is from 1.2 s.');
        $splitter->flushSilent(2.19);
        $this->assertCount(1, $this->copies);
    }

    public function testNothingIsHandedOutWhenTheSilenceThatEndsAnUtteranceIsNoLongerThanTheLastOfIt(): void
    {
        $splitter = $this->earlySplitter(silenceSeconds: 0.3);
        $this->speak('alice', from: 0.0, seconds: 1.0, splitter: $splitter);

        $splitter->flushSilent(1.27);
        $this->assertSame([], $this->copies);
        $this->assertSame([], $this->utterances);

        $splitter->flushSilent(1.29);
        $this->assertSame([], $this->copies, 'It ends at 0.3 s of silence, and has no time left before it.');
        $this->assertCount(1, $this->utterances);
    }

    public function testHandsOutACopyForEachSpeakerWhenTheirSilenceBegins(): void
    {
        $splitter = $this->earlySplitter();
        $this->speak('alice', from: 0.0, seconds: 1.0, splitter: $splitter);
        $this->speak('bob', from: 0.2, seconds: 1.0, splitter: $splitter);

        // Alice was silent from 0.98 s, Bob from 1.18 s.
        $splitter->flushSilent(1.3);
        $this->assertSame(['alice'], array_column($this->copies, 0));

        $splitter->flushSilent(1.5);
        $this->assertSame(['alice', 'bob'], array_column($this->copies, 0));

        $this->speak('bob', from: 1.52, seconds: 0.5, splitter: $splitter);
        $this->assertSame(['bob'], $this->drops, 'Only the copy of who went on is dropped.');
    }

    public function testEndsAnUtteranceWithACopyInProgressWhenEverythingIsFinished(): void
    {
        $splitter = $this->earlySplitter();
        $this->speak('alice', from: 0.0, seconds: 1.0, splitter: $splitter);
        $splitter->flushSilent(1.3);

        $splitter->flushAll();

        $this->assertCount(1, $this->utterances);
        $this->assertSame(file_get_contents($this->utterances[0][1]), file_get_contents($this->copies[0][1]));
    }

    public function testHandsOutNothingWhenTheRecordingIsGoneAndGoesOnWithoutIt(): void
    {
        $splitter = $this->earlySplitter();
        $this->speak('alice', from: 0.0, seconds: 1.0, splitter: $splitter);
        array_map(unlink(...), glob("{$this->directory}/utterance-*.wav"));

        $splitter->flushSilent(1.3);

        $this->assertSame([], $this->copies, 'There is nothing to copy.');
        $this->assertSame([], glob("{$this->directory}/early-*.wav"));
        $this->speak('alice', from: 1.32, seconds: 0.1, splitter: $splitter);
        $this->assertSame([], $this->drops, 'Nothing was handed out, so nothing is dropped.');
    }

    public function testHandsOutNothingWhenTheRecordingLacksTheEndOfWhatWasSaid(): void
    {
        $splitter = $this->earlySplitter();
        $this->speak('alice', from: 0.0, seconds: 1.0, splitter: $splitter);
        $recording = glob("{$this->directory}/utterance-*.wav")[0];
        file_put_contents($recording, substr(file_get_contents($recording), 0, -100));

        $splitter->flushSilent(1.3);

        $this->assertSame([], $this->copies, 'A copy that lacks the end is not what she said.');
        $this->assertSame([], glob("{$this->directory}/early-*.wav"));
    }

    public function testHandsOutNothingWithoutAnythingToHandItTo(): void
    {
        $this->speak('alice', from: 0.0, seconds: 1.0);

        $this->splitter->flushSilent(1.3);

        $this->assertSame([], glob("{$this->directory}/early-*.wav"));
    }

    /**
     * A splitter that hands out copies, with where they go.
     */
    private function earlySplitter(float $silenceSeconds = UtteranceSplitter::SILENCE_SECONDS): UtteranceSplitter
    {
        return new UtteranceSplitter($this->directory, function (string $userId, string $wavPath, float $seconds) {
            $this->utterances[] = [$userId, $wavPath, $seconds];
        }, $silenceSeconds, onEarly: function (string $userId, string $wavPath, float $seconds) {
            $this->copies[] = [$userId, $wavPath, $seconds];
        }, onEarlyDropped: function (string $userId) {
            $this->drops[] = $userId;
        });
    }

    /**
     * Pushes 20 ms frames of audio, as the voice client does while someone talks.
     */
    private function speak(string $userId, float $from, float $seconds, ?UtteranceSplitter $splitter = null): void
    {
        for ($frame = 0; $frame < $seconds * 50; $frame++) {
            ($splitter ?? $this->splitter)->push($userId, str_repeat("\x01\x00", self::FRAME_BYTES / 2), $from + $frame * 0.02);
        }
    }

    private function assertValidWav(string $path, float $seconds): void
    {
        $wav = file_get_contents($path);
        $header = unpack('a4riff/Vsize/a4wave/a4fmt/Vfmtsize/vformat/vchannels/Vrate/Vbyterate/vblock/vbits/a4data/Vdatasize', $wav);

        $this->assertSame('RIFF', $header['riff']);
        $this->assertSame('WAVE', $header['wave']);
        $this->assertSame(2, $header['channels']);
        $this->assertSame(48000, $header['rate']);
        $this->assertSame(16, $header['bits']);
        $this->assertSame((int) round($seconds * 48000 * 4), $header['datasize']);
        $this->assertSame(44 + $header['datasize'], strlen($wav));
    }
}
