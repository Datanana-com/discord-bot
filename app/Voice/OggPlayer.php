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
 * Plays Ogg Opus files by sending their packets to Discord itself, one every 20 ms, from the moment a file is
 * given to it.
 *
 * The voice library's playFile() starts an ffmpeg for every file and waits half a second before it sends the
 * first packet, which is also the gap between two sentences of an answer. Piper's sentences are already Ogg Opus
 * files, so there is nothing to convert and nothing to wait for: the first packet goes out after a short head
 * start, and a file given while another plays follows it in the same stream, without a gap.
 *
 * A stream is the speaking flag on, the packets at their pace, five frames of silence, as Discord asks, so that
 * the listeners' decoders let out what they still hold, and the speaking flag off. A file that comes during the
 * silence goes on with the stream instead.
 */
final class OggPlayer implements Player
{
    /** Seconds between two packets: a packet is 20 ms of audio. */
    public const float FRAME = 0.02;

    /** Seconds from a file being given, while nothing plays, to its first packet: time for the speaking flag to reach the listeners first. */
    public const float HEAD_START = 0.04;

    /** Frames of silence that end a stream. */
    private const int SILENCE_FRAMES = 5;

    /** A packet later than this is sent at once, and the pace starts over from it: no burst of late packets after the loop stalled. */
    private const float LATE = 0.1;

    /** @var list<array{packets: list<string>, onStart: (callable(): void)|null, done: Deferred<null>}> The files waiting to be played, in order. */
    private array $queue = [];

    /** @var array{packets: list<string>, sent: int, onStart: (callable(): void)|null, done: Deferred<null>}|null The file being played. */
    private ?array $current = null;

    /** Whether a stream is going: the speaking flag is on. */
    private bool $streaming = false;

    /** Frames of silence still to send at the end of the stream. */
    private int $silence = 0;

    /** When the next packet is due. */
    private float $next = 0.0;

    private ?TimerInterface $timer = null;

    /** Counts the stops: what a tick calls out to (the waiter of a file that is over, the first packet's callback) may stop the player, and the tick checks this before it goes on. */
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

    public function play(string $path, ?callable $onStart = null): PromiseInterface
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            return reject(new RuntimeException("Could not read {$path}."));
        }

        try {
            $packets = OggOpus::packets($bytes);
        } catch (RuntimeException $e) {
            return reject(new RuntimeException("Could not play {$path}: {$e->getMessage()}", previous: $e));
        }

        $done = new Deferred();
        $this->queue[] = ['packets' => $packets, 'onStart' => $onStart, 'done' => $done];

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
     * Starts a stream for the files in the queue.
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
     * Sends what is due now: the next packet of the file, a frame of silence, or the end of the stream.
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

        if ($this->current['sent'] === count($this->current['packets'])) {
            $done = $this->current['done'];
            $this->current = null;
            $this->silence = self::SILENCE_FRAMES;
            // Whoever waited for the file may give the next one now, which then takes this slot: no gap between two sentences.
            $done->resolve(null);

            if ($generation === $this->generation) {
                $this->tick();
            }

            return;
        }

        $this->send($this->current['packets'][$this->current['sent']++]);

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
