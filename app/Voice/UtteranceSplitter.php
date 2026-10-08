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

    /** @var array<string, array{writer: WavWriter, bytes: int, lastAudioAt: float, longestGap: float, longGaps: int}> */
    private array $utterances = [];

    private int $count = 0;

    /**
     * @param string $directory Where utterance WAV files are written.
     * @param Closure(string $userId, string $wavPath, float $seconds): void $onUtterance Called with each finished utterance.
     * @param float $silenceSeconds Gap in a speaker's audio that ends their utterance: longer for people who pause in the middle of a sentence.
     * @param (Closure(string $userId, float $longestGap, int $longGaps): void)|null $onGaps Called after $onUtterance with the longest gap
     *        between two packets inside the utterance and how many gaps were {@see LONG_GAP_SECONDS} or longer. Not for an utterance that is dropped.
     */
    public function __construct(
        private readonly string $directory,
        private readonly Closure $onUtterance,
        private readonly float $silenceSeconds = self::SILENCE_SECONDS,
        private readonly ?Closure $onGaps = null,
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
            $this->utterances[$userId] = ['writer' => $writer, 'bytes' => 0, 'lastAudioAt' => $now, 'longestGap' => 0.0, 'longGaps' => 0];
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
     * Whether someone is saying something that hasn't ended yet: they spoke less than a gap ago.
     */
    public function isSpeaking(string $userId): bool
    {
        return isset($this->utterances[$userId]);
    }

    /**
     * Finishes the utterances of everyone who has been silent long enough.
     */
    public function flushSilent(float $now): void
    {
        foreach ($this->utterances as $userId => $utterance) {
            if ($now - $utterance['lastAudioAt'] >= $this->silenceSeconds) {
                $this->finish((string) $userId);
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
