<?php

declare(strict_types=1);

namespace App\Voice;

use Discord\Voice\VoiceClient;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * Plays through the voice library: {@see VoiceClient::playFile()} starts an ffmpeg for the file and sends its
 * first packet half a second later, which is also the gap between two files. {@see OggPlayer} sends the packets
 * itself, without the half second, and from the first of them; this one is what VOICE_PLAYER=library plays with,
 * and what the feature tests play with, as their voice client plays nothing.
 */
final class LibraryPlayer implements Player
{
    /** Counts the stops: a sentence that was still being encoded when the player was stopped is not played once its file is there. */
    private int $stops = 0;

    public function __construct(private readonly VoiceClient $vc)
    {
    }

    public function play(Sentence $sentence, ?callable $onStart = null): PromiseInterface
    {
        $stops = $this->stops;

        // The library plays files, and the sentence's is written once all of it is encoded.
        return $sentence->whole()->then(function () use ($sentence, $onStart, $stops) {
            if ($stops !== $this->stops) {
                return null;
            }

            // The library says nothing when it sends a packet: the file is handed over now, and heard half a second later.
            if ($onStart !== null) {
                $onStart();
            }

            return $this->vc->playFile($sentence->path);
        });
    }

    public function stop(): void
    {
        $this->stops++;

        try {
            $this->vc->stop();
        } catch (Throwable) {
            // Nothing was playing: the bot was between two sentences.
        }
    }
}
