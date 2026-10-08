<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Voice\OggPlayer;
use App\Voice\Sentence;
use Discord\Voice\Rtp\UDP;
use Discord\Voice\VoiceClient;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use ReflectionProperty;
use RuntimeException;
use Tests\Fixtures\ManualTimers;
use Tests\Ogg;
use Throwable;

use function React\Promise\resolve;

final class OggPlayerTest extends TestCase
{
    /** The time the player is told it is. */
    private float $now = 1000.0;

    private ManualTimers $loop;

    private VoiceClient $vc;

    /** Whether the voice client refuses to say the bot speaks, as it does before the voice connection is up. */
    private bool $notReady = false;

    /** @var list<array{0: float, 1: int}> When the voice client was told the bot speaks, or stopped. */
    private array $speaking = [];

    /** @var list<array{0: float, 1: string}> When each packet was sent, and the packet. */
    private array $sent = [];

    private string $directory;

    protected function setUp(): void
    {
        $this->loop = new ManualTimers();
        $this->directory = sys_get_temp_dir() . '/ogg-player-test-' . uniqid();
        mkdir($this->directory);
        $this->vc = static::getStubBuilder(VoiceClient::class)->disableOriginalConstructor()->onlyMethods(['setSpeaking'])->getStub();
        $this->vc->method('setSpeaking')->willReturnCallback(function (int $speaking): void {
            if ($this->notReady) {
                throw new RuntimeException('Voice Client is not ready.');
            }

            $this->speaking[] = [$this->now, $speaking];
        });
        $this->vc->udp = new class ($this) extends UDP {
            public function __construct(private readonly OggPlayerTest $test)
            {
            }

            public function sendBuffer(string $data): void
            {
                $this->test->sent($data);
            }
        };
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob("{$this->directory}/*") ?: []);
        rmdir($this->directory);
    }

    public function sent(string $packet): void
    {
        $this->sent[] = [$this->now, $packet];
    }

    public function testSendsTheFirstPacketAfterTheHeadStartAndTheRestEvery20Ms(): void
    {
        $player = $this->player();
        $startedAt = $doneAt = null;

        $done = $player->play($this->file('a', 'b', 'c'), function () use (&$startedAt) {
            $startedAt = $this->now;
        });
        $done->then(function () use (&$doneAt) {
            $doneAt = $this->now;
        });

        // The bot says it speaks at once, and nothing is sent before the head start.
        $this->assertSame([[1000.0, VoiceClient::MICROPHONE]], $this->speaking);
        $this->assertSame([], $this->sent);
        $this->assertNull($startedAt);

        $this->assertEqualsWithDelta(0.04, $this->tick(), 0.0001, 'The head start.');
        $this->assertSame([[1000.04, 'a']], $this->sent);
        $this->assertSame(1000.04, $startedAt, 'The file started when its first packet was sent.');

        $this->assertEqualsWithDelta(0.02, $this->tick(), 0.0001);
        $this->assertEqualsWithDelta(0.02, $this->tick(), 0.0001);
        $this->assertSame(['a', 'b', 'c'], $this->audio());
        $this->assertEqualsWithDelta([1000.04, 1000.06, 1000.08], array_column($this->sent, 0), 0.0001);
        $this->assertNull($doneAt, 'Not over while its last packet may still be on its way.');

        // The slot after the last packet: the file is over, and the first of five frames of silence takes it.
        $this->tick();
        $this->assertEqualsWithDelta(1000.10, $doneAt, 0.0001);
        $this->assertSame(1, $this->silence());

        for ($frame = 0; $frame < 4; $frame++) {
            $this->assertEqualsWithDelta(0.02, $this->tick(), 0.0001);
        }

        $this->assertSame(5, $this->silence());
        $this->assertSame([[1000.0, VoiceClient::MICROPHONE]], $this->speaking, 'Still speaking while the silence is sent.');

        // Then the stream is over.
        $this->tick();
        $this->assertEqualsWithDelta([[1000.0, VoiceClient::MICROPHONE], [1000.20, VoiceClient::NOT_SPEAKING]], $this->speaking, 0.0001);
        $this->assertSame([], $this->loop->pending(), 'Nothing is left in the loop.');
        $this->assertSame(5, $this->silence(), 'Nothing more was sent.');
    }

    public function testAFileGivenWhileAnotherPlaysFollowsItWithoutAGap(): void
    {
        $player = $this->player();
        $player->play($this->file('a1', 'a2'));
        $this->tick();
        $this->tick();
        $this->assertSame(['a1', 'a2'], $this->audio());

        $startedAt = null;
        $player->play($this->file('b1', 'b2'), function () use (&$startedAt) {
            $startedAt = $this->now;
        });

        // The slot after a2 is b1's: no head start, no silence, no second word about speaking.
        $this->tick();
        $this->assertSame(['a1', 'a2', 'b1'], $this->audio());
        $this->assertEqualsWithDelta([1000.04, 1000.06, 1000.08], array_column($this->sent, 0), 0.0001);
        $this->assertEqualsWithDelta(1000.08, $startedAt, 0.0001);
        $this->assertSame(0, $this->silence());
        $this->assertCount(1, $this->speaking);

        $this->tick();
        $this->assertSame(['a1', 'a2', 'b1', 'b2'], $this->audio());
    }

    public function testAFileGivenAsSoonAsTheOneBeforeIsOverFollowsItWithoutAGap(): void
    {
        $player = $this->player();
        $next = null;
        // Like the bot, which gives the next sentence once the one before it was played.
        $player->play($this->file('a1'))->then(function () use ($player, &$next) {
            $next = $player->play($this->file('b1'));
        });

        $this->tick();
        $this->tick();

        $this->assertInstanceOf(PromiseInterface::class, $next);
        $this->assertSame(['a1', 'b1'], $this->audio());
        $this->assertEqualsWithDelta([1000.04, 1000.06], array_column($this->sent, 0), 0.0001);
        $this->assertSame(0, $this->silence());
    }

    public function testAFileGivenDuringTheSilenceGoesOnWithTheStream(): void
    {
        $player = $this->player();
        $player->play($this->file('a1'));
        $this->tick();
        $this->tick();
        $this->tick();
        $this->assertSame(2, $this->silence(), 'Two frames of silence were sent after the file.');

        $player->play($this->file('b1'));
        $this->tick();

        $this->assertSame(['a1', 'b1'], $this->audio());
        $this->assertSame(2, $this->silence(), 'The silence stopped for the file.');
        $this->assertCount(1, $this->speaking, 'The bot never stopped speaking.');

        // Then the stream ends as usual: the file is over, five frames of silence, and the bot stops speaking.
        for ($slot = 0; $slot < 6; $slot++) {
            $this->tick();
        }

        $this->assertSame(7, $this->silence());
        $this->assertSame(VoiceClient::NOT_SPEAKING, end($this->speaking)[1]);
        $this->assertSame([], $this->loop->pending());
    }

    public function testStopCutsTheFileOffAndDropsWhatWaited(): void
    {
        $player = $this->player();
        $results = [];
        $player->play($this->file('a1', 'a2', 'a3', 'a4'))->then(function () use (&$results) {
            $results[] = 'a';
        });
        $player->play($this->file('b1'))->then(function () use (&$results) {
            $results[] = 'b';
        });
        $this->tick();
        $this->tick();

        $player->stop();

        // What was sent stays sent; five frames of silence go out at once, and the bot stops speaking.
        $this->assertSame(['a1', 'a2'], $this->audio());
        $this->assertSame(5, $this->silence());
        $this->assertEqualsWithDelta([1000.06, 1000.06, 1000.06, 1000.06, 1000.06], array_column(array_slice($this->sent, 2), 0), 0.0001);
        $this->assertEqualsWithDelta([[1000.0, VoiceClient::MICROPHONE], [1000.06, VoiceClient::NOT_SPEAKING]], $this->speaking, 0.0001);
        // Whoever waited for the files is told, so that the bot goes on.
        $this->assertSame(['a', 'b'], $results);
        $this->assertSame([], $this->loop->pending());

        // A file given after that starts a stream of its own.
        $player->play($this->file('c1'));
        $this->tick();
        $this->assertSame(['a1', 'a2', 'c1'], $this->audio());
        $this->assertCount(3, $this->speaking);
    }

    public function testStopWhileNothingPlaysDoesNothing(): void
    {
        $this->player()->stop();

        $this->assertSame([], $this->speaking);
        $this->assertSame([], $this->sent);
    }

    public function testStopFromWhoeverWaitedForAFileEndsTheStream(): void
    {
        $player = $this->player();
        // Like the bot when the call stops while a sentence is spoken.
        $player->play($this->file('a1'))->then(fn () => $player->stop());
        $player->play($this->file('b1'));

        $this->tick();
        $this->tick();

        $this->assertSame(['a1'], $this->audio(), 'The next file was dropped.');
        $this->assertSame(5, $this->silence());
        $this->assertSame([VoiceClient::MICROPHONE, VoiceClient::NOT_SPEAKING], array_column($this->speaking, 1), 'The bot stopped speaking once.');
        $this->assertSame([], $this->loop->pending());
    }

    public function testStopFromTheFirstPacketsCallbackEndsTheStream(): void
    {
        $player = $this->player();
        // Like a call that stopped from the "Started speaking" callback would.
        $player->play($this->file('a1', 'a2'), fn () => $player->stop());
        $this->tick();

        $this->assertSame(['a1'], $this->audio());
        $this->assertSame(5, $this->silence());
        $this->assertSame([VoiceClient::MICROPHONE, VoiceClient::NOT_SPEAKING], array_column($this->speaking, 1), 'The bot stopped speaking once.');
        $this->assertSame([], $this->loop->pending(), 'No packet is due any more.');
    }

    public function testPacesWithTheClockThatOnlyGoesForwardByDefault(): void
    {
        $player = new OggPlayer($this->vc, $this->loop);
        $clock = (new ReflectionProperty(OggPlayer::class, 'clock'))->getValue($player);

        // hrtime() counts from an arbitrary moment, such as the machine starting, not from 1970 like the wall
        // clock, which steps on some machines.
        $this->assertEqualsWithDelta(hrtime(true) / 1e9, $clock(), 0.05);
        $this->assertGreaterThan(1e8, abs(microtime(true) - $clock()), 'Not the wall clock.');
    }

    public function testAPacketLateAfterAStallIsSentAtOnceAndThePaceStartsOverFromIt(): void
    {
        $player = $this->player();
        $player->play($this->file('a1', 'a2', 'a3'));
        $this->tick();

        // The event loop stood still for half a second: the timer for a2 fires late.
        $this->now += 0.5;
        $this->loop->elapse($this->loop->pending()[0]);
        $this->assertEqualsWithDelta(1000.54, $this->sent[1][0], 0.0001);

        // a3 is not sent at once to catch up: it comes a frame after a2.
        $this->assertEqualsWithDelta(0.02, $this->tick(), 0.0001);
        $this->assertEqualsWithDelta(1000.56, $this->sent[2][0], 0.0001);
    }

    public function testRefusesAFileThatIsNotOggOpus(): void
    {
        $player = $this->player();
        $wav = $this->whole('RIFF....WAVEfmt ');

        $this->assertRejected($player->play($wav), "Could not play {$wav->path}: Not an Ogg Opus file: it does not start with the OpusHead and OpusTags headers.");
        // Nor one of nothing at all.
        $nothing = $this->whole('');
        $this->assertRejected($player->play($nothing), "Could not play {$nothing->path}: Not an Ogg Opus file: it does not start with the OpusHead and OpusTags headers.");
        $this->assertSame([], $this->speaking, 'Nothing was played.');
        $this->assertSame([], $this->loop->pending());
    }

    public function testPlaysWhatIsWholeOfAFileThatIsCutOff(): void
    {
        $player = $this->player();
        $player->play($this->whole(substr(Ogg::opus(['a1', 'a2', str_repeat('a3', 100)], perPage: 1), 0, -5)));
        $this->tick();
        $this->tick();
        $this->tick();

        $this->assertSame(['a1', 'a2'], $this->audio());
        $this->assertSame(1, $this->silence(), 'The file was over after what was whole of it.');
    }

    public function testAFileWithNoPacketsIsOverAtOnce(): void
    {
        $player = $this->player();
        $over = false;
        $player->play($this->file())->then(function () use (&$over) {
            $over = true;
        });
        $this->tick();

        $this->assertTrue($over);
        $this->assertSame([], $this->audio());
        $this->assertSame(1, $this->silence());
    }

    public function testPlaysASentenceWhileTheRestOfItStillComesFromTheEncoder(): void
    {
        $player = $this->player();
        $stream = Ogg::opus(['a1', 'a2', 'a3'], perPage: 1);
        $pages = [strlen($stream) - 2 * strlen(Ogg::page(['a2'], 3)), strlen(Ogg::page(['a2'], 3))];
        $sentence = $this->sentence();
        $startedAt = null;
        $over = false;

        // The headers and the first packet are there: it is played from here.
        $sentence->write(substr($stream, 0, $pages[0]));
        $player->play($sentence, function () use (&$startedAt) {
            $startedAt = $this->now;
        })->then(function () use (&$over) {
            $over = true;
        });
        $this->assertEqualsWithDelta(OggPlayer::HEAD_START, $this->tick(), 1e-9);
        $this->assertSame(['a1'], $this->audio());
        $this->assertSame($this->now, $startedAt);

        // The encoder is faster than the sentence is long: the next packets are there before their turn.
        $sentence->write(substr($stream, $pages[0]));
        $this->assertSame(['a1'], $this->audio(), 'A packet that came is sent at its own time, not when it came.');
        $this->assertEqualsWithDelta(OggPlayer::FRAME, $this->tick(), 1e-9);
        $this->assertEqualsWithDelta(OggPlayer::FRAME, $this->tick(), 1e-9);
        $this->assertSame(['a1', 'a2', 'a3'], $this->audio());

        // All that came was sent, and the encoder has not ended: the sentence is not over.
        $this->tick();
        $this->assertFalse($over);
        $this->assertSame(0, $this->silence());
        $this->assertSame([], $this->loop->pending(), 'Nothing to send until the encoder says more.');

        $sentence->end();
        $this->assertTrue($over);
        $this->assertSame(1, $this->silence(), 'The stream ends as after any sentence.');
    }

    public function testWaitsForAPacketThatComesLaterThanItsTurnAndGoesOnWithoutABurst(): void
    {
        $player = $this->player();
        $stream = Ogg::opus(['a1', 'a2', 'a3', 'a4'], perPage: 1);
        $page = strlen(Ogg::page(['a2'], 3));
        $first = strlen($stream) - 3 * $page;
        $sentence = $this->sentence();
        $over = false;
        $sentence->write(substr($stream, 0, $first));
        $player->play($sentence)->then(function () use (&$over) {
            $over = true;
        });
        $this->tick();

        // The voice is slower than it speaks: at the second packet's turn, the encoder has not written it.
        $this->tick();
        $this->assertSame(['a1'], $this->audio());
        $this->assertSame(0, $this->silence(), 'The hole is not filled with silence: the sentence is not over.');
        $this->assertSame([], $this->loop->pending());
        $this->assertFalse($over);

        // It comes 70 ms late, with the one after it: one is sent at once, and the other a frame later, not both at once.
        $this->now += 0.07;
        $sentence->write(substr($stream, $first, 2 * $page));
        $this->assertSame(['a1', 'a2'], $this->audio());
        $this->assertSame($this->now, $this->sent[1][0]);
        $this->assertEqualsWithDelta(OggPlayer::FRAME, $this->tick(), 1e-9);
        $this->assertSame(['a1', 'a2', 'a3'], $this->audio());

        // Starved again, and then the encoder ends having written its last packet meanwhile.
        $this->tick();
        $this->now += 0.5;
        $sentence->write(substr($stream, $first + 2 * $page));
        $this->assertSame(['a1', 'a2', 'a3', 'a4'], $this->audio());
        $sentence->end();
        $this->assertFalse($over, 'The last packet has its 20 ms before the sentence is over.');
        $this->assertEqualsWithDelta(OggPlayer::FRAME, $this->tick(), 1e-9);
        $this->assertTrue($over);
        $this->assertSame(1, $this->silence());
    }

    public function testASentenceGivenBeforeItsFirstPacketCameStartsWhenThatComes(): void
    {
        $player = $this->player();
        $stream = Ogg::opus(['a1'], perPage: 1);
        $headers = strlen($stream) - strlen(Ogg::page(['a1'], 2, Ogg::LAST));
        $sentence = $this->sentence();
        $startedAt = null;
        $sentence->write(substr($stream, 0, $headers));
        $player->play($sentence, function () use (&$startedAt) {
            $startedAt = $this->now;
        });

        $this->tick();
        $this->assertNull($startedAt);
        $this->assertSame([], $this->sent);

        $this->now += 0.01;
        $sentence->write(substr($stream, $headers));

        $this->assertSame($this->now, $startedAt);
        $this->assertSame(['a1'], $this->audio());
    }

    public function testASentenceWhoseEncoderFailsIsPlayedAsFarAsItCameAndThenFails(): void
    {
        $player = $this->player();
        $stream = Ogg::opus(['a1', 'a2'], perPage: 1);
        $sentence = $this->sentence();
        $sentence->write(substr($stream, 0, strlen($stream) - strlen(Ogg::page(['a2'], 3, Ogg::LAST))));
        $failed = $player->play($sentence);
        $next = false;
        $player->play($this->file('b1'))->then(function () use (&$next) {
            $next = true;
        });
        $this->tick();

        // The encoder ends in the middle of the sentence, before the player ran out of packets.
        $sentence->fail(new RuntimeException('ffmpeg exited with code 1'));
        $this->assertSame(['a1'], $this->audio());

        $this->tick();
        $this->assertRejected($failed, 'ffmpeg exited with code 1');
        // What waited behind it is played in its place, in the same stream.
        $this->assertSame(['a1', 'b1'], $this->audio());
        $this->tick();
        $this->assertTrue($next);

        // One that fails while the player waits for its next packet fails at once.
        $starved = $this->sentence();
        $starved->write(substr($stream, 0, strlen($stream) - strlen(Ogg::page(['a2'], 3, Ogg::LAST))));
        $failed = $player->play($starved);
        $this->tick();
        $this->tick();
        $this->assertSame([], $this->loop->pending());
        $starved->fail(new RuntimeException('ffmpeg timed out after 120s'));
        $this->assertRejected($failed, 'ffmpeg timed out after 120s');
        $this->assertSame(['a1', 'b1', 'a1'], $this->audio());
        $this->assertCount(1, $this->loop->pending(), 'The stream ends with its silence.');
    }

    public function testASentenceThatTurnsOutNotToBeOpusWhileItPlaysFails(): void
    {
        $player = $this->player();
        $sentence = $this->sentence();
        // The start of a page, as far as anyone can tell.
        $sentence->write('Og');
        $failed = $player->play($sentence);
        $sentence->write('g, what is this?');
        $this->tick();

        $this->assertRejected($failed, "Could not play {$sentence->path}: Not an Ogg Opus file: it does not start with the OpusHead and OpusTags headers.");
        $this->assertSame([], $this->audio());
    }

    public function testStopWhileItWaitsForAPacketEndsTheStreamAndWhatComesLaterIsNotSent(): void
    {
        $player = $this->player();
        $stream = Ogg::opus(['a1', 'a2'], perPage: 1);
        $first = strlen($stream) - strlen(Ogg::page(['a2'], 3, Ogg::LAST));
        $sentence = $this->sentence();
        $result = 'nothing yet';
        $sentence->write(substr($stream, 0, $first));
        $player->play($sentence)->then(function ($value) use (&$result) {
            $result = $value;
        });
        $this->tick();
        $this->tick();
        $this->assertSame([], $this->loop->pending());

        $player->stop();

        $this->assertNull($result);
        $this->assertSame(5, $this->silence());
        $this->assertSame(VoiceClient::NOT_SPEAKING, end($this->speaking)[1]);

        // The encoder goes on, for nobody.
        $sentence->write(substr($stream, $first));
        $sentence->end();
        $this->assertSame(['a1'], $this->audio());
        $this->assertSame([], $this->loop->pending());

        // The next sentence waits for nothing: what comes of it while it plays is sent at its turn.
        $next = $this->sentence();
        $next->write(substr($stream, 0, $first));
        $player->play($next);
        $this->tick();
        $next->write(substr($stream, $first));
        $this->assertSame(['a1', 'a1'], $this->audio());
        $this->assertEqualsWithDelta(OggPlayer::FRAME, $this->tick(), 1e-9);
        $this->assertSame(['a1', 'a1', 'a2'], $this->audio());
        $player->stop();

        // A sentence that waits behind the one being played, and gets its packets meanwhile, changes nothing either.
        $playing = $this->sentence();
        $playing->write(substr($stream, 0, $first));
        $waiting = $this->sentence();
        $waiting->write(substr($stream, 0, $first));
        $player->play($playing);
        $player->play($waiting);
        $this->tick();
        $this->tick();
        $waiting->write(substr($stream, $first));
        $this->assertSame([], $this->loop->pending(), 'The one being played still waits for its own packet.');
        $this->assertSame(['a1', 'a1', 'a2', 'a1'], $this->audio());
        $playing->write(substr($stream, $first));
        $this->assertSame(['a1', 'a1', 'a2', 'a1', 'a2'], $this->audio());
        $player->stop();
    }

    public function testRejectsWhenTheVoiceClientIsNotReady(): void
    {
        $this->notReady = true;
        $player = $this->player();

        $this->assertRejected($player->play($this->file('a1')), 'Voice Client is not ready.');
        $this->assertSame([], $this->loop->pending());

        // Once it is ready, the next file plays.
        $this->notReady = false;
        $player->play($this->file('b1'));
        $this->tick();
        $this->assertSame(['b1'], $this->audio());
    }

    public function testEndsQuietlyWhenTheVoiceClientClosedWhileAFilePlayed(): void
    {
        $player = $this->player();
        $over = [];
        $player->play($this->file('a1'))->then(function () use (&$over) {
            $over[] = 'a';
        });
        $this->tick();

        // The call is closed under the player: the voice client no longer takes the speaking flag.
        $this->notReady = true;

        // Neither the stream ending by itself nor a stop has anyone left to tell, and neither throws: the file's
        // end, five frames of silence, and the end of the stream.
        for ($slot = 0; $slot < 6; $slot++) {
            $this->tick();
        }

        $player->play($this->file('b1'))->then(function () use (&$over) {
            $over[] = 'b';
        }, function () use (&$over) {
            $over[] = 'b refused';
        });
        $this->assertSame(['a', 'b refused'], $over, 'The second file could not start: the voice client is not ready.');

        $this->notReady = false;
        $player->play($this->file('c1'))->then(function () use (&$over) {
            $over[] = 'c';
        });
        $this->tick();
        $this->notReady = true;
        $player->stop();

        $this->assertSame(['a', 'b refused', 'c'], $over);
        $this->assertSame(['a1', 'c1'], $this->audio());
        $this->assertSame([VoiceClient::MICROPHONE, VoiceClient::MICROPHONE], array_column($this->speaking, 1), 'Nobody could be told the bot stopped speaking, either time.');
        $this->assertSame([], $this->loop->pending());
    }

    public function testPlaysIntoNothingWhenThereIsNoMediaConnection(): void
    {
        $vc = static::getStubBuilder(VoiceClient::class)->disableOriginalConstructor()->onlyMethods(['setSpeaking'])->getStub();
        $player = new OggPlayer($vc, $this->loop, clock: fn () => $this->now);
        $over = false;

        $player->play($this->file('a1'))->then(function () use (&$over) {
            $over = true;
        });

        // The packet, the file's end with the first frame of silence, four more, and the end of the stream.
        for ($slot = 0; $slot < 7; $slot++) {
            $this->tick();
        }

        $this->assertTrue($over);
        $this->assertSame([], $this->loop->pending());
    }

    private function player(): OggPlayer
    {
        return new OggPlayer($this->vc, $this->loop, clock: fn () => $this->now);
    }

    /**
     * A sentence that is whole, of these packets: its file is in the test's folder.
     */
    private function file(string ...$packets): Sentence
    {
        return $this->whole(Ogg::opus($packets));
    }

    /**
     * A sentence that is whole, of these bytes.
     */
    private function whole(string $bytes): Sentence
    {
        $sentence = $this->sentence();
        $sentence->write($bytes);
        $sentence->end();

        return $sentence;
    }

    /**
     * A sentence the voice has spoken, of which nothing has come from the encoder yet.
     */
    private function sentence(): Sentence
    {
        static $number = 0;

        return new Sentence(sprintf('%s/%d.ogg', $this->directory, ++$number), resolve(null));
    }

    /**
     * Lets the one pending timer run out, with the clock moved on by as much.
     *
     * @return float The seconds it was set for.
     */
    private function tick(): float
    {
        $pending = $this->loop->pending();
        $this->assertCount(1, $pending, 'One timer is pending.');
        $this->now += $pending[0];
        $this->loop->elapse($pending[0]);

        return $pending[0];
    }

    /**
     * @return list<string> The packets sent that aren't silence, in order.
     */
    private function audio(): array
    {
        return array_values(array_filter(array_column($this->sent, 1), fn (string $packet) => $packet !== UDP::SILENCE_FRAME));
    }

    private function silence(): int
    {
        return count($this->sent) - count($this->audio());
    }

    private function assertRejected(PromiseInterface $promise, string $message): void
    {
        $rejected = null;
        $promise->then(null, function (Throwable $e) use (&$rejected) {
            $rejected = $e;
        });

        $this->assertInstanceOf(RuntimeException::class, $rejected);
        $this->assertSame($message, $rejected->getMessage());
    }
}
