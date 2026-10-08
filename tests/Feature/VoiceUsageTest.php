<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Voice\VoiceSession;
use React\Promise\Deferred;

use function React\Async\await;

/**
 * The statistics of a call are written when nobody waits for the bot, not between a sentence ending and
 * whisper being asked, nor while an answer is spoken: a write blocks the event loop for a few milliseconds.
 */
final class VoiceUsageTest extends VoiceTestCase
{
    public function testWritesNothingWhileTheQuestionIsTranscribedNorWhileTheAnswerIsSpokenAndEverythingAfterwards(): void
    {
        $speech = new Deferred();
        $this->playing = $speech->promise();
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // The sentence has ended, and whisper is not asked yet.
        $this->speakAndWait($vc, '555');
        $this->runFor(0.3);
        $this->assertSame([], $this->writtenEvents(), 'Nothing is written between the end of a sentence and whisper being asked.');

        // The answer is posted, and the bot is speaking it.
        $this->transcribe();
        $this->waitUntil(fn () => $this->played !== [] && $this->sent !== [], 'the answer to be posted and spoken');
        $this->runFor(0.3);
        $this->assertSame([], $this->writtenEvents(), 'Nothing is written while the bot speaks.');
        // /stats counts what is held, without writing it.
        $this->assertSame(['calls' => 1, 'utterances' => 1, 'answers' => 1], array_intersect_key($this->usage(), array_flip(['calls', 'utterances', 'answers'])));
        $this->assertSame([], $this->writtenEvents(), 'Neither does /stats.');

        // Once the answer is spoken, nobody waits: what happened is written, in order.
        $speech->resolve(null);
        $this->waitUntil(fn () => $this->writtenEvents() !== [], 'the statistics to be written');
        $this->assertSame(['call_started', 'utterance', 'answered'], $this->writtenEvents());
        $this->assertSame([], $this->loggedProblems());

        await($session->stop());
        $this->assertSame(['call_started', 'utterance', 'answered', 'call_ended'], $this->writtenEvents());
    }

    public function testWritesWhatIsHeldWhenACallThatNobodySpokeInEnds(): void
    {
        $session = VoiceSession::start($this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame([], $this->writtenEvents());

        await($session->stop());

        $this->assertSame(['call_started', 'call_ended'], $this->writtenEvents());
    }

    public function testWritesWhatIsHeldOfACallThatEndsWhileItIsAnswered(): void
    {
        $speech = new Deferred();
        $this->playing = $speech->promise();
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [] && $this->sent !== [], 'the answer to be posted and spoken');
        $this->assertSame([], $this->writtenEvents());

        // The call ends in the middle of the sentence, which is cut off: nothing is lost with the call.
        await($session->stop());

        $this->assertSame(['call_started', 'utterance', 'answered', 'call_ended'], $this->writtenEvents());
        $speech->resolve(null);
    }

    public function testWritesNothingOfOneCallWhileAnotherIsBeingAnswered(): void
    {
        foreach ([self::BOT_ID, '555'] as $userId) {
            $this->voiceStates[] = $this->inVoice($userId, '201');
        }

        $first = VoiceSession::start($this->voiceClient($firstChannel = $this->voiceChannel()), $firstChannel, $this->discord);
        $second = VoiceSession::start($secondVc = $this->voiceClient($secondChannel = $this->voiceChannel('201', '101')), $secondChannel, $this->discord);
        $speech = new Deferred();
        $this->playing = $speech->promise();
        $this->speak($secondVc, ssrc: 1555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [] && $this->sent !== [], 'the answer in the second call to be posted and spoken');

        // The first call has nothing to answer, and ends: its end waits for the second one's answer.
        await($first->stop());
        $this->runFor(0.3);
        $this->assertSame([], $this->writtenEvents(), 'The second call is being answered.');

        $speech->resolve(null);
        $this->waitUntil(fn () => count($this->writtenEvents()) === 5, 'the statistics of both calls to be written');
        $events = $this->writtenEvents();
        sort($events);
        $this->assertSame(['answered', 'call_ended', 'call_started', 'call_started', 'utterance'], $events);
        await($second->stop());
    }
}
