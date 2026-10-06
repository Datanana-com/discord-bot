<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Voice\OggPlayer;
use Discord\Voice\Rtp\UDP;
use Discord\Voice\VoiceClient;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use RuntimeException;
use Tests\Fixtures\ManualTimers;
use Tests\Ogg;
use Throwable;

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
        file_put_contents($wav = "{$this->directory}/not.ogg", 'RIFF....WAVEfmt ');

        $this->assertRejected($player->play($wav), "Could not play {$wav}: Not an Ogg Opus file: it does not start with the OpusHead and OpusTags headers.");
        $this->assertRejected($player->play("{$this->directory}/missing.ogg"), "Could not read {$this->directory}/missing.ogg.");
        $this->assertSame([], $this->speaking, 'Nothing was played.');
        $this->assertSame([], $this->loop->pending());
    }

    public function testPlaysWhatIsWholeOfAFileThatIsCutOff(): void
    {
        $player = $this->player();
        file_put_contents($cut = "{$this->directory}/cut.ogg", substr(Ogg::opus(['a1', 'a2', str_repeat('a3', 100)], perPage: 1), 0, -5));

        $player->play($cut);
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
     * An Ogg Opus file of these packets, in the test's folder.
     */
    private function file(string ...$packets): string
    {
        static $number = 0;
        file_put_contents($path = sprintf('%s/%d.ogg', $this->directory, ++$number), Ogg::opus($packets));

        return $path;
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
