<?php

declare(strict_types=1);

namespace App\Voice;

use Closure;
use Discord\Voice\Recording\WavWriter;

/**
 * Splits each speaker's audio into utterances: a speaker's utterance ends once their
 * audio has stopped for a moment. Discord clients stop sending packets while someone
 * is silent, so a gap between packets is a reliable end-of-speech signal.
 *
 * Audio is 48 kHz, 16-bit stereo PCM, as decoded by the voice client.
 */
final class UtteranceSplitter
{
    /** The bytes of a WAV file before its audio. */
    private const int WAV_HEADER_BYTES = 44;

    /** Bytes per second of 48 kHz, 16-bit stereo PCM. */
    public const int BYTES_PER_SECOND = 48000 * 2 * 2;

    /** Gap in a speaker's audio that ends their utterance, unless the splitter is given another. */
    public const float SILENCE_SECONDS = 0.6;

    /** Shorter utterances (coughs, clicks, "hm") are dropped. */
    public const float MIN_SECONDS = 0.5;

    /** Longer utterances are cut, so long monologues still get handled. */
    private const float MAX_SECONDS = 30.0;

    /** A gap between two packets this long or longer is counted: it is where someone might go on after pausing. */
    public const float LONG_GAP_SECONDS = 0.2;

    /**
     * How long before the end of an utterance whisper is given what was said so far: the early copy. The end is
     * the silence that ends it, so this is the start of the last {@see EARLY_SECONDS} of it.
     */
    public const float EARLY_SECONDS = 0.3;

    /** @var array<string, array{writer: WavWriter, bytes: int, lastAudioAt: float, longestGap: float, longGaps: int, early: bool}> */
    private array $utterances = [];

    private int $count = 0;

    private int $copies = 0;

    /**
     * @param string $directory Where utterance WAV files are written.
     * @param Closure(string $userId, string $wavPath, float $seconds): void $onUtterance Called with each finished utterance.
     * @param float $silenceSeconds Gap in a speaker's audio that ends their utterance: longer for people who pause in the middle of a sentence.
     * @param (Closure(string $userId, float $longestGap, int $longGaps): void)|null $onGaps Called after $onUtterance with the longest gap
     *        between two packets inside the utterance and how many gaps were {@see LONG_GAP_SECONDS} or longer. Not for an utterance that is dropped.
     * @param (Closure(string $userId, string $wavPath, float $seconds): void)|null $onEarly Called with a copy of what a speaker said so far, as a WAV
     *        file of its own that is the caller's to delete, once they have been silent for all but the last {@see EARLY_SECONDS} of $silenceSeconds: it is
     *        what the utterance will be if they say nothing more. Once for each silence, and not for an utterance too short to be kept, or when
     *        $silenceSeconds is no longer than that. Without it, nothing is copied.
     * @param (Closure(string $userId): void)|null $onEarlyDropped Called when the speaker goes on after $onEarly was called, which makes the copy something
     *        they did not stop at.
     */
    public function __construct(
        private readonly string $directory,
        private readonly Closure $onUtterance,
        private readonly float $silenceSeconds = self::SILENCE_SECONDS,
        private readonly ?Closure $onGaps = null,
        private readonly ?Closure $onEarly = null,
        private readonly ?Closure $onEarlyDropped = null,
    ) {
    }

    /**
     * Adds a chunk of a speaker's audio.
     */
    public function push(string $userId, string $pcm, float $now): void
    {
        if (! isset($this->utterances[$userId])) {
            $writer = new WavWriter(sprintf('%s/utterance-%d.wav', $this->directory, ++$this->count));
            $writer->open();
            $this->utterances[$userId] = ['writer' => $writer, 'bytes' => 0, 'lastAudioAt' => $now, 'longestGap' => 0.0, 'longGaps' => 0, 'early' => false];
        }

        // They are not done: the copy of what they said so far is not what they said.
        if ($this->utterances[$userId]['early']) {
            $this->utterances[$userId]['early'] = false;
            $this->onEarlyDropped?->__invoke($userId);
        }

        $gap = $now - $this->utterances[$userId]['lastAudioAt'];
        $this->utterances[$userId]['longestGap'] = max($this->utterances[$userId]['longestGap'], $gap);
        $this->utterances[$userId]['longGaps'] += $gap >= self::LONG_GAP_SECONDS ? 1 : 0;

        $this->utterances[$userId]['writer']->write($pcm);
        $this->utterances[$userId]['bytes'] += strlen($pcm);
        $this->utterances[$userId]['lastAudioAt'] = $now;

        if ($this->utterances[$userId]['bytes'] >= self::MAX_SECONDS * self::BYTES_PER_SECOND) {
            $this->finish($userId);
        }
    }

    /**
     * Finishes the utterances of everyone who has been silent long enough.
     */
    public function flushSilent(float $now): void
    {
        foreach ($this->utterances as $userId => $utterance) {
            $silence = $now - $utterance['lastAudioAt'];

            if ($silence >= $this->silenceSeconds) {
                $this->finish((string) $userId);
            } elseif ($this->onEarly !== null && ! $utterance['early'] && $this->silenceSeconds > self::EARLY_SECONDS && $silence >= $this->silenceSeconds - self::EARLY_SECONDS) {
                $this->copyEarly((string) $userId);
            }
        }
    }

    /**
     * Finishes every utterance in progress.
     */
    public function flushAll(): void
    {
        foreach (array_keys($this->utterances) as $userId) {
            $this->finish((string) $userId);
        }
    }

    /**
     * Hands out a copy of what a speaker said so far. The recording goes on: its header is only right once it is
     * closed, so the copy is written whole, with a header of its own.
     */
    private function copyEarly(string $userId): void
    {
        ['writer' => $writer, 'bytes' => $bytes] = $this->utterances[$userId];

        // Too short to be kept: it is dropped once it is over, and whisper would hear what nobody uses.
        if ($bytes < self::MIN_SECONDS * self::BYTES_PER_SECOND) {
            return;
        }

        $wav = @file_get_contents($writer->getPath());

        // What was written must be all there is: a copy that lacks the end of it is not what they said.
        if ($wav === false || strlen($wav) !== self::WAV_HEADER_BYTES + $bytes) {
            return;
        }

        $copy = new WavWriter(sprintf('%s/early-%d.wav', $this->directory, ++$this->copies));
        $copy->open();
        $copy->write(substr($wav, self::WAV_HEADER_BYTES));
        $copy->finalize();

        $this->utterances[$userId]['early'] = true;
        ($this->onEarly)($userId, $copy->getPath(), $bytes / self::BYTES_PER_SECOND);
    }

    private function finish(string $userId): void
    {
        ['writer' => $writer, 'bytes' => $bytes, 'longestGap' => $longestGap, 'longGaps' => $longGaps] = $this->utterances[$userId];
        unset($this->utterances[$userId]);

        $writer->finalize();

        if ($bytes < self::MIN_SECONDS * self::BYTES_PER_SECOND) {
            unlink($writer->getPath());

            return;
        }

        ($this->onUtterance)($userId, $writer->getPath(), $bytes / self::BYTES_PER_SECOND);
        $this->onGaps?->__invoke($userId, $longestGap, $longGaps);
    }
}
