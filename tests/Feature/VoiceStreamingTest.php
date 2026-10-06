<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Voice\VoiceSession;
use React\Promise\Deferred;

use function React\Async\await;

/**
 * Claude's answer is spoken sentence by sentence, while Claude is still writing it.
 */
final class VoiceStreamingTest extends VoiceTestCase
{
    private const string QUESTION = '> **Alice:** Hey Claude, what time is it?';

    private const string ANSWER = 'Sure. It is a quarter past four. Time for a cup of tea. The kettle is already on.';

    /** The answer's sentences: "Sure." is too short to be spoken on its own. */
    private const array SENTENCES = ['Sure. It is a quarter past four.', 'Time for a cup of tea.', 'The kettle is already on.'];

    public function testSpeaksTheFirstSentenceWhileClaudeIsStillWriting(): void
    {
        // Claude writes one sentence and the space after it, pauses, then writes the rest.
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past four. ', 'Time for a cup', ' of tea.'),
            'FAKE_CLAUDE_PAUSE' => '10',
        ]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');

        // It was synthesized and played while Claude had not finished its answer.
        $this->assertSame(["{$session->directory}/claude-2.ogg"], $this->played);
        $this->assertSame('It is a quarter past four.', file_get_contents($this->played[0]), 'Piper was given the first sentence.');
        $this->assertSame([], $this->logged('Claude answered'), 'Claude is still writing.');
        $this->assertSame([], $this->sent, 'The text channel gets the answer once it is whole.');
        $this->assertStringNotContainsString('Claude:', $this->transcript($session));

        touch($this->claudeResume);
        $this->waitUntil(fn () => count($this->played) === 2, 'the rest of the answer to be spoken');

        $this->assertSame("{$session->directory}/claude-3.ogg", $this->played[1], 'Each sentence is saved next to the recordings.');
        $this->assertSame('Time for a cup of tea.', file_get_contents($this->played[1]));

        // The whole answer is posted in one message, and is one line of the transcript.
        $this->assertSame([self::QUESTION . "\nIt is a quarter past four. Time for a cup of tea."], $this->sent);
        $this->assertStringEndsWith("] Claude: It is a quarter past four. Time for a cup of tea.\n", $this->transcript($session));

        // How long it took from the end of the question to the first audio is logged, once.
        $messages = array_map(fn ($record) => $record->message, $this->logs->getRecords());
        $this->assertSame(['Started speaking', 'Claude answered'], array_values(array_intersect($messages, ['Started speaking', 'Claude answered'])));
        $started = $this->logged('Started speaking')[0];
        $this->assertSame(['guild', 'session', 'user', 'ms'], array_keys($started));
        $this->assertSame('555', $started['user']);
        $this->assertGreaterThanOrEqual($this->logged('Transcribed')[0]['ms'], $started['ms'], 'It includes the transcription.');

        // The "answered" statistic still measures until the answer is posted.
        $this->assertSame(1, $this->usage()['answers']);
        $this->assertGreaterThan($started['ms'], $this->usage()['answer_ms']);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testSpeaksTheSentencesInOrderOneAtATime(): void
    {
        // The voice client refuses to play a file while it is playing another one.
        $this->playSeconds = 0.2;
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream(self::ANSWER)]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->played) === 3, 'every sentence to be spoken');

        $this->assertSame(self::SENTENCES, array_map(file_get_contents(...), $this->played));
        $this->assertSame(
            ["{$session->directory}/claude-2.ogg", "{$session->directory}/claude-3.ogg", "{$session->directory}/claude-4.ogg"],
            $this->played,
        );
        $this->assertSame([self::QUESTION . "\n" . self::ANSWER], $this->sent);
        $this->assertCount(1, $this->logged('Started speaking'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testSynthesizesTheNextSentencesWhileOneIsBeingSpoken(): void
    {
        $finished = new Deferred();
        $this->playing = $finished->promise();
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream(self::ANSWER)]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => is_file("{$session->directory}/claude-4.ogg"), 'the last sentence to be synthesized');

        $this->assertCount(1, $this->played, 'The first sentence is still being spoken.');

        $finished->resolve(null);
        $this->waitUntil(fn () => count($this->played) === 3, 'every sentence to be spoken');
        $this->assertSame(self::SENTENCES, array_map(file_get_contents(...), $this->played));
    }

    public function testSpeaksAnAnswerThatWasNotStreamedSentenceBySentence(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeResult(self::ANSWER)]);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->played) === 3, 'every sentence to be spoken');

        $this->assertSame(self::SENTENCES, array_map(file_get_contents(...), $this->played));
    }

    public function testAnswersTheNextQuestionOnceThePreviousAnswerWasSpoken(): void
    {
        $this->playSeconds = 0.3;
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Alice and Bob ask at the same time.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $this->waitUntil(fn () => count($this->played) === 2, 'both answers to be spoken');

        $this->assertCount(2, $this->sent);
        $this->assertSame([], $this->loggedProblems(), 'The second answer did not start over the first one.');
    }

    public function testDoesNotSpeakTheRestWhenTheCallStopsWhileClaudeIsWriting(): void
    {
        // Like the real voice client, which never says a sentence finished when it is closed while speaking it.
        $this->playing = (new Deferred())->promise();
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past four. ', 'Time for a cup of tea.'),
            'FAKE_CLAUDE_PAUSE' => '10',
        ]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');

        $ended = $session->stop();
        touch($this->claudeResume);
        await($ended);

        // The answer is still posted whole, followed by the call's summary. Claude's stand-in gives both the same text.
        $this->assertSame(
            [self::QUESTION . "\nIt is a quarter past four. Time for a cup of tea.", 'It is a quarter past four. Time for a cup of tea.'],
            $this->sent,
        );
        $this->assertCount(1, $this->played, 'The rest was not spoken.');
        $this->assertSame(["{$session->directory}/claude-2.ogg"], glob("{$session->directory}/claude-*"), 'Nor synthesized.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testDoesNotSpeakTheSentencesThatWereWaitingWhenTheCallStops(): void
    {
        $this->playing = (new Deferred())->promise();
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream(self::ANSWER)]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        // The call stops while the first sentence is spoken, with the other two ready to be.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => is_file("{$session->directory}/claude-4.ogg"), 'the last sentence to be synthesized');
        await($session->stop());

        $this->assertCount(1, $this->played);
        $this->assertSame([self::QUESTION . "\n" . self::ANSWER, self::ANSWER], $this->sent);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testDoesNotSynthesizeTheSentencesThatWereWaitingWhenTheCallStops(): void
    {
        $this->playing = (new Deferred())->promise();
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream(self::ANSWER), 'FAKE_PIPER_DELAY' => '0.3']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        // The call stops while the first sentence is spoken and Piper is busy with the second.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');
        await($session->stop());

        // The second one was already being synthesized; the third never is.
        $this->assertSame(["{$session->directory}/claude-2.ogg", "{$session->directory}/claude-3.ogg"], glob("{$session->directory}/claude-*"));
        $this->assertCount(1, $this->played);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testPostsAndCountsAFailureAfterPartOfTheAnswerWasSpoken(): void
    {
        $finished = new Deferred();
        $this->playing = $finished->promise();
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => implode("\n", [
                self::claudeText('It is a quarter past four. '),
                self::claudeText('Time for'),
                self::claudeResult('API Error: Connection error.', isError: true),
            ]),
            'FAKE_CLAUDE_EXIT' => '1',
            'FAKE_CLAUDE_PAUSE' => '10',
        ]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');
        touch($this->claudeResume);
        $this->waitUntil(fn () => $this->sent !== [], 'the failure to be posted');

        $this->assertSame(["Sorry, I couldn't get an answer from Claude. (Claude Code: API Error: Connection error.)"], $this->sent);

        // The first sentence is still being spoken: the next question waits for it, or its answer would be refused.
        $this->runFor(0.2);
        $this->assertSame([], $this->loggedProblems());

        $finished->resolve(null);
        $this->waitUntil(fn () => $this->loggedProblems() !== [], 'the failure to be logged');

        $this->assertSame(['Voice reply failed: Claude Code: API Error: Connection error.'], $this->loggedProblems());
        $this->assertSame([1, 0], [$this->usage()['failures'], $this->usage()['answers']]);
        // The sentence Claude had started is not spoken: the call is told that it failed.
        $this->waitUntil(fn () => count($this->played) === 2, 'the call to be told');
        $this->assertSame('Sorry, something went wrong.', file_get_contents($this->played[1]));
        $this->assertStringNotContainsString('quarter past four', $this->transcript($session), 'An answer that failed is not in the transcript.');
    }

    public function testCountsAFailureWhenASentenceCannotBeSpoken(): void
    {
        $finished = new Deferred();
        $this->playing = $finished->promise();
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream(self::ANSWER), 'FAKE_PIPER_FAILS_ON' => 'cup of tea']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');

        // Piper fails on the second sentence while the first one is spoken, which is not interrupted.
        $this->runFor(0.5);
        $this->assertSame([], $this->loggedProblems());

        $finished->resolve(null);
        $this->waitUntil(fn () => $this->loggedProblems() !== [], 'the failure to be logged');

        $this->assertCount(1, $this->loggedProblems());
        $this->assertStringStartsWith('Voice reply failed: ', $this->loggedProblems()[0]);
        $this->assertStringEndsWith('fake-piper exited with code 1: The voice model could not be loaded.', $this->loggedProblems()[0]);
        $this->assertSame(["{$session->directory}/claude-2.ogg"], $this->played, 'Nothing is spoken after the sentence that is missing.');

        // The answer itself was posted, then that it couldn't be spoken, and both are counted.
        $this->assertSame([self::QUESTION . "\n" . self::ANSWER, "Sorry, I couldn't say that out loud. The bot's logs say why."], $this->sent);
        $this->assertSame([1, 1], [$this->usage()['failures'], $this->usage()['answers']]);
    }
}
