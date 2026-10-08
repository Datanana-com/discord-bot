<?php

declare(strict_types=1);

namespace App\Voice;

use Discord\Voice\VoiceClient;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * Plays through the voice library: {@see VoiceClient::playFile()} starts an ffmpeg for the file and sends its
 * first packet half a second later, which is also the gap between two files. {@see OggPlayer} sends the packets
 * itself, without the half second; this one is what VOICE_PLAYER=library plays with, and what the feature tests
 * play with, as their voice client plays nothing.
 */
final class LibraryPlayer implements Player
{
    public function __construct(private readonly VoiceClient $vc)
    {
    }

    public function play(string $path, ?callable $onStart = null): PromiseInterface
    {
        // The library says nothing when it sends a packet: the file is handed over now, and heard half a second later.
        if ($onStart !== null) {
            $onStart();
        }

        return $this->vc->playFile($path);
    }

    public function stop(): void
    {
        try {
            $this->vc->stop();
        } catch (Throwable) {
            // Nothing was playing: the bot was between two sentences.
        }
    }
}
