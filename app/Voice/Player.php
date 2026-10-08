<?php

declare(strict_types=1);

namespace App\Voice;

use React\Promise\PromiseInterface;

/**
 * Plays the sentences of a call into its voice channel: those of an answer, "Okay." and what the bot says on
 * its own. One sentence at a time, in the order they are given.
 *
 * The bot has two: {@see OggPlayer}, which sends a sentence's packets itself while they still come from the
 * encoder, and {@see LibraryPlayer}, which hands the sentence's file to the voice library once it is whole.
 * VOICE_PLAYER says which: see {@see VoiceSession::player()}.
 */
interface Player
{
    /**
     * @return PromiseInterface<null> Resolves once the player could start on the sentence, were it given it now:
     *                                {@see OggPlayer} from its first packet, {@see LibraryPlayer} once its file
     *                                is whole. Rejects when the sentence failed before that. Whoever plays a
     *                                sentence waits for this first, and only then looks whether it is still
     *                                wanted: nothing may change between that look and the sentence being heard.
     */
    public function ready(Sentence $sentence): PromiseInterface;

    /**
     * @param Sentence $sentence One the player is ready for: see {@see ready()}.
     * @param (callable(): void)|null $onStart Called when the first packet of the sentence is sent: the moment
     *                                         the call starts hearing it.
     * @return PromiseInterface<null> Resolves once the whole sentence was sent. Rejects when it can't be played
     *                                at all, or its encoder failed while it was played. When playing is stopped
     *                                meanwhile it may resolve or say nothing more, as the voice library does:
     *                                whoever waits for it also waits for what stopped it.
     */
    public function play(Sentence $sentence, ?callable $onStart = null): PromiseInterface;

    /**
     * Cuts off the sentence that is playing, and drops those that wait to be played after it.
     */
    public function stop(): void;
}
