<?php

declare(strict_types=1);

namespace App\Voice;

use Closure;
use Discord\Voice\Rtp\UDP;
use Discord\Voice\VoiceClient;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

use function React\Promise\reject;

/**
 * Plays sentences by sending their Opus packets to Discord itself, one every 20 ms, from the moment a sentence
 * is given to it: also while the rest of its packets still come from the encoder.
 *
 * The voice library's playFile() starts an ffmpeg for every file and waits half a second before it sends the
 * first packet, which is also the gap between two sentences of an answer. A sentence is already Ogg Opus when it
 * gets here, so there is nothing to convert and nothing to wait for: the first packet goes out after a short head
 * start, and a sentence given while another plays follows it in the same stream, without a gap.
 *
 * A stream is the speaking flag on, the packets at their pace, five frames of silence, as Discord asks, so that
 * the listeners' decoders let out what they still hold, and the speaking flag off. A sentence that comes during
 * the silence goes on with the stream instead.
 *
 * When a sentence's next packet has not come yet, as with a voice slower than it speaks, nothing is sent until
 * it has: the sentence goes on from there at its pace, without a burst to make up for the wait.
 */
final class OggPlayer implements Player
{
    /** Seconds between two packets: a packet is 20 ms of audio. */
    public const float FRAME = 0.02;

    /** Seconds from a sentence being given, while nothing plays, to its first packet: time for the speaking flag to reach the listeners first. */
    public const float HEAD_START = 0.04;

    /** Frames of silence that end a stream. */
    private const int SILENCE_FRAMES = 5;

    /** A packet later than this is sent at once, and the pace starts over from it: no burst of late packets after the loop stalled. */
    private const float LATE = 0.1;

    /** @var list<array{sentence: Sentence, onStart: (callable(): void)|null, done: Deferred<null>}> The sentences waiting to be played, in order. */
    private array $queue = [];

    /** @var array{sentence: Sentence, sent: int, onStart: (callable(): void)|null, done: Deferred<null>}|null The sentence being played. */
    private ?array $current = null;

    /** Whether the sentence being played waits for its next packet to come from the encoder: no timer is armed meanwhile. */
    private bool $starved = false;

    /** Whether a stream is going: the speaking flag is on. */
    private bool $streaming = false;

    /** Frames of silence still to send at the end of the stream. */
    private int $silence = 0;

    /** When the next packet is due. */
    private float $next = 0.0;

    private ?TimerInterface $timer = null;

    /** Counts the stops: what a tick calls out to (the waiter of a sentence that is over, the first packet's callback) may stop the player, and the tick checks this before it goes on. */
    private int $generation = 0;

    private readonly Closure $clock;

    /**
     * @param (callable(): float)|null $clock The time now, in seconds: the tests give it one that stands still. By
     *                                        default the clock that only ever goes forward, like the event loop's
     *                                        timers: the wall clock steps on some machines (WSL2 among them), and a
     *                                        step back would hold the next packet until it had caught up.
     */
    public function __construct(
        private readonly VoiceClient $vc,
        private readonly LoopInterface $loop,
        private readonly float $headStart = self::HEAD_START,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null ? static fn (): float => hrtime(true) / 1e9 : $clock(...);
    }

    public function play(Sentence $sentence, ?callable $onStart = null): PromiseInterface
    {
        try {
            // What came of it so far says whether it can be played at all.
            $sentence->packets();
        } catch (RuntimeException $e) {
            return reject(self::unplayable($sentence, $e));
        }

        $done = new Deferred();
        $this->queue[] = ['sentence' => $sentence, 'onStart' => $onStart, 'done' => $done];
        $sentence->watch($this->came(...));

        if (! $this->streaming) {
            $this->begin();
        }

        return $done->promise();
    }

    public function stop(): void
    {
        $this->generation++;
        $this->cancel();
        $files = [...($this->current === null ? [] : [$this->current]), ...$this->queue];
        $this->current = null;
        $this->queue = [];
        $this->silence = 0;
        $this->starved = false;

        if ($this->streaming) {
            $this->streaming = false;

            // At once, not at the pace: the listeners' decoders have nothing more to wait for.
            for ($frame = 0; $frame < self::SILENCE_FRAMES; $frame++) {
                $this->send(UDP::SILENCE_FRAME);
            }

            $this->speaking(false);
        }

        foreach ($files as $file) {
            $file['done']->resolve(null);
        }
    }

    /**
     * Starts a stream for the sentences in the queue.
     */
    private function begin(): void
    {
        try {
            $this->vc->setSpeaking(VoiceClient::MICROPHONE);
        } catch (Throwable $e) {
            // The voice client isn't ready: nothing can be played, as the library would say.
            foreach (array_splice($this->queue, 0) as $file) {
                $file['done']->reject($e);
            }

            return;
        }

        $this->streaming = true;
        $this->next = ($this->clock)() + $this->headStart;
        $this->schedule();
    }

    /**
     * Sends what is due now: the next packet of the sentence, a frame of silence, or the end of the stream.
     */
    private function tick(): void
    {
        $this->timer = null;
        $generation = $this->generation;

        if ($this->current === null) {
            if ($this->queue !== []) {
                $this->current = [...array_shift($this->queue), 'sent' => 0];
                $this->silence = 0;
            } elseif ($this->silence > 0) {
                $this->silence--;
                $this->send(UDP::SILENCE_FRAME);
                $this->schedule();

                return;
            } else {
                $this->streaming = false;
                $this->speaking(false);

                return;
            }
        }

        $sentence = $this->current['sentence'];

        try {
            $packets = $sentence->packets();
            $failure = $sentence->failure();
        } catch (RuntimeException $e) {
            $packets = [];
            $failure = self::unplayable($sentence, $e);
        }

        if ($this->current['sent'] >= count($packets)) {
            // The encoder has not come this far yet: the slot goes by, and the sentence goes on when its next packet is there.
            if ($failure === null && ! $sentence->isOver()) {
                $this->starved = true;

                return;
            }

            $done = $this->current['done'];
            $this->current = null;
            $this->silence = self::SILENCE_FRAMES;

            // Whoever waited for the sentence may give the next one now, which then takes this slot: no gap between two sentences.
            if ($failure === null) {
                $done->resolve(null);
            } else {
                $done->reject($failure);
            }

            if ($generation === $this->generation) {
                $this->tick();
            }

            return;
        }

        $this->send($packets[$this->current['sent']++]);

        if ($this->current['sent'] === 1 && $this->current['onStart'] !== null) {
            ($this->current['onStart'])();

            // Whoever was told may have stopped the player: then there is nothing more to send.
            if ($generation !== $this->generation) {
                return;
            }
        }

        $this->schedule();
    }

    /**
     * More of a sentence came from its encoder, or it is over: when the one being played waited for its next
     * packet, it goes on now, and the pace starts over from here. When it was another sentence, the one being
     * played finds nothing and waits again.
     */
    private function came(): void
    {
        if (! $this->starved) {
            return;
        }

        $this->starved = false;
        $this->next = ($this->clock)() + self::FRAME;
        $this->tick();
    }

    private static function unplayable(Sentence $sentence, RuntimeException $e): RuntimeException
    {
        return new RuntimeException("Could not play {$sentence->path}: {$e->getMessage()}", previous: $e);
    }

    /**
     * Arms the timer for the next packet's slot, and moves the slot on by a frame.
     */
    private function schedule(): void
    {
        $now = ($this->clock)();

        // The loop stalled, and the packet just sent went out late: the next one goes out a frame from now, and
        // the pace starts over from there, instead of a burst of late packets.
        if ($now - $this->next > self::LATE) {
            $this->next = $now + self::FRAME;
        }

        // A stop cancels this timer, so it never fires for a stream that is over.
        $this->timer = $this->loop->addTimer(max(0.0, $this->next - $now), $this->tick(...));
        $this->next += self::FRAME;
    }

    private function cancel(): void
    {
        if ($this->timer !== null) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }
    }

    private function send(string $packet): void
    {
        // No media connection, as before the call is set up, or once it is closed: there is nowhere to send it.
        if (isset($this->vc->udp)) {
            $this->vc->udp->sendBuffer($packet);
        }
    }

    private function speaking(bool $on): void
    {
        try {
            $this->vc->setSpeaking($on ? VoiceClient::MICROPHONE : VoiceClient::NOT_SPEAKING);
        } catch (Throwable) {
            // The voice client closed meanwhile: there is nobody to tell.
        }
    }
}
