<?php

declare(strict_types=1);

namespace App\Voice;

use React\Promise\PromiseInterface;

/**
 * Plays the Ogg Opus files of a call into its voice channel: the sentences of an answer, "Okay." and what the
 * bot says on its own. One file at a time, in the order they are given.
 *
 * The bot has two: {@see OggPlayer}, which sends a file's packets itself, and {@see LibraryPlayer}, which hands
 * the file to the voice library. VOICE_PLAYER says which: see {@see VoiceSession::player()}.
 */
interface Player
{
    /**
     * @param (callable(): void)|null $onStart Called when the first packet of the file is sent: the moment the
     *                                         call starts hearing it.
     * @return PromiseInterface<null> Resolves once the whole file was sent. Rejects when the file can't be played
     *                                at all. When playing is stopped meanwhile it may resolve or say nothing more,
     *                                as the voice library does: whoever waits for it also waits for what stopped it.
     */
    public function play(string $path, ?callable $onStart = null): PromiseInterface;

    /**
     * Cuts off what is playing, and drops what waits to be played after it.
     */
    public function stop(): void;
}
