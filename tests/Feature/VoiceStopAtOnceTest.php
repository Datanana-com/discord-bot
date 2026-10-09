<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Voice\VoiceSession;
use Discord\Voice\VoiceClient;
use React\Promise\Deferred;

/**
 * What is said is transcribed when it ends, not when its turn comes behind the answers before it: the stop
 * phrase and the leave phrase are heard at once, and a new question replaces what the bot was going to say
 * to whoever asks it.
 *
 * Alice, Bob and Carol are 555, 666 and 777. Whatever whisper hears in a test is what the last one to speak
 * said, so a test waits for a sentence to be transcribed before the next one is said.
 */
final class VoiceStopAtOnceTest extends VoiceTestCase
{
    private const string QUESTION = 'Hey Claude, what time is it?';

    private const string ANSWER = 'It is a quarter past four.';

    private const string STOP = 'Stop, Claude.';

    private const string LEAVE = 'Disconnect Claude.';

    public function testTheStopPhraseSaidWhileClaudeIsWritingKeepsTheAnswerFromBeingSpoken(): void
    {
        // Claude starts on its answer, and takes long over the first sentence.
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is', ' a quarter past four.'), 'FAKE_CLAUDE_PAUSE' => '10']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->logged('Claude started answering') !== [], 'Claude to start writing');

        // Alice doesn't want it any more. There is nothing to talk over yet: the bot hasn't said a word.
        $this->says($vc, '555', self::STOP);
        $this->waitUntil(fn () => count($this->logged('Utterance ended')) === 2, 'Alice to finish speaking');
        // Long enough for whisper, which has nothing to wait for: also on a machine that is busy.
        $this->runFor(1.5);

        // Only now does Claude get to the end of its sentence.
        touch($this->claudeResume);
        $this->waitUntil(fn () => str_contains($this->transcript($session), '] Alice: ' . self::STOP . "\n"), 'the stop phrase to be transcribed');
        $this->runFor(0.5);

        $this->assertSame([], $this->played, 'The answer she stopped was never spoken.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAnAnswerNobodyHeardAnythingOfIsNotWrittenToItsEndNorPosted(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is', ' a quarter past four.'), 'FAKE_CLAUDE_PAUSE' => '30']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->logged('Claude started answering') !== [], 'Claude to start writing');
        $writing = $this->claudeCalls()[0]['pid'];
        $this->assertTrue($this->isRunning($writing));

        $this->hears($vc, '555', self::STOP);

        // It is stopped while Claude is still writing: nothing waits for the file that would let it go on.
        $stopped = $this->contexts('Stopped answering');
        $this->assertCount(1, $stopped);
        $this->assertSame(['user', 'by', 'reason', 'ms', 'spoken'], array_keys($stopped[0]));
        $this->assertSame(['user' => '555', 'by' => '555', 'reason' => 'the stop phrase'], array_slice($stopped[0], 0, 3));
        $this->assertFalse($stopped[0]['spoken'], 'Nothing of it was heard.');
        // Since she stopped saying it: the silence that ended her sentence, and whisper.
        $this->assertGreaterThanOrEqual(0, $stopped[0]['ms']);
        $this->assertLessThan(5000, $stopped[0]['ms']);
        $this->waitUntil(fn () => ! $this->isRunning($writing), 'Claude Code to end', 3.0);

        // Nobody heard it, so it is nobody's: not in the text channel, and not in the transcript.
        $this->runFor(0.3);
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->played);
        $this->assertSame([], $this->logged('Claude answered'));
        $this->assertSame('Alice: ' . self::QUESTION . "\nAlice: " . self::STOP . "\n", $this->untimed($this->transcript($session)));
        $this->assertSame(0, $this->usage()['answers']);
        $this->assertSame(0, $this->usage()['failures'], 'Stopping it is no failure.');

        // What she asks next doesn't wait for it, and finds a Claude Code process waiting, as any question does.
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is Thursday.'), 'FAKE_CLAUDE_PAUSE' => '0']);
        $this->ask($vc, '555', 'Claude, what day is it?');

        $this->assertSame(['> **Alice:** Claude, what day is it?' . "\nIt is Thursday."], $this->sent);
        $this->assertTrue($this->claudeCalls()[1]['waited']);
        $this->assertSame([], $this->loggedProblems());
        $this->assertLogsNeverMention('quarter past four', 'Thursday', 'what time', 'Stop, Claude');
    }

    public function testClaudeIsNotAskedAgainWhenItIsStoppedBeforeItWroteAnything(): void
    {
        // Claude takes long to start, as in the call this was built after.
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '30']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->claudeCalls() !== [], 'Claude to be asked');
        $asked = $this->claudeCalls()[0]['pid'];
        // The summary at the end of the test is not to take as long.
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0']);

        $this->hears($vc, '555', self::STOP);
        $this->waitUntil(fn () => ! $this->isRunning($asked), 'Claude Code to end', 3.0);
        $this->runFor(0.3);

        // A waiting process that ends without a word is asked again in a new one, but not one that was stopped.
        $this->assertCount(1, $this->claudeCalls());
        $this->assertSame([], $this->played);
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testEndsTheClaudeCodeThatWasStartedForTheQuestionWhenNoneWasWaiting(): void
    {
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is', ' a quarter past four.'),
            'FAKE_CLAUDE_PAUSE' => '30',
            'FAKE_CLAUDE_WAITING_LEAVES' => '1',
        ]);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->waitUntil(fn () => $this->waitingClaudes() !== [] && ! $this->isRunning($this->waitingClaudes()[0]), 'the waiting Claude Code to end by itself');

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->logged('Claude started answering') !== [], 'Claude to start writing');
        $this->assertFalse($this->claudeCalls()[0]['waited']);
        $writing = $this->claudeCalls()[0]['pid'];

        $this->hears($vc, '555', self::STOP);
        $this->waitUntil(fn () => ! $this->isRunning($writing), 'Claude Code to end', 3.0);
        // The summary at the end of the test is not to take as long.
        $this->setProcessEnv(['FAKE_CLAUDE_PAUSE' => '0']);
        $this->runFor(0.3);

        $this->assertSame([], $this->played);
        $this->assertSame([], $this->sent);
        $this->assertSame(['No Claude Code process was waiting for the question'], $this->loggedProblems());
    }

    public function testEndsTheClaudeCodeThatWasStartedBecauseTheWaitingOneDidNotAnswer(): void
    {
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is', ' a quarter past four.'),
            'FAKE_CLAUDE_PAUSE' => '30',
            'FAKE_CLAUDE_WAITING_FAILS' => '1',
        ]);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->logged('Claude started answering') !== [], 'Claude to start writing');
        $this->assertFalse($this->claudeCalls()[0]['waited']);
        $writing = $this->claudeCalls()[0]['pid'];

        $this->hears($vc, '555', self::STOP);
        $this->waitUntil(fn () => ! $this->isRunning($writing), 'Claude Code to end', 3.0);
        // The summary at the end of the test is not to take as long.
        $this->setProcessEnv(['FAKE_CLAUDE_PAUSE' => '0']);
        $this->runFor(0.3);

        $this->assertSame([], $this->played);
        $this->assertSame([], $this->sent);
        $this->assertCount(1, $this->claudeCalls());
        $this->assertCount(1, $this->loggedProblems());
        $this->assertStringStartsWith('The waiting Claude Code process did not answer: ', $this->loggedProblems()[0]);
    }

    public function testTheStopPhraseDropsTheQuestionsOfWhoeverSaidItThatWaitAndNobodyElses(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        // The sentence the bot speaks to Bob is a long one: it never ends by itself here.
        $this->playing = (new Deferred())->promise();
        $this->ask($vc, '666', 'Hey Claude, are you there?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to Bob to be spoken');

        // Alice and Carol each ask something meanwhile. Both wait their turn behind the answer to Bob.
        $this->hears($vc, '555', self::QUESTION);
        $this->hears($vc, '777', 'Claude, what day is it?');
        $this->assertSame([], $this->cutOff, 'Neither of them cut the answer to Bob.');
        $this->assertCount(1, $this->claudeCalls());

        // Alice has had enough: the answer to Bob is cut, and her own question is no longer answered.
        $this->playing = null;
        $this->hears($vc, '555', self::STOP);
        $this->assertSame([$this->played[0]], $this->cutOff);

        // Carol's is: she didn't say it.
        $this->waitUntil(fn () => count($this->sent) === 2, 'the answer to Carol');
        $this->runFor(0.3);
        $this->assertSame('> **Carol:** Claude, what day is it?' . "\n" . self::ANSWER, $this->sent[1]);
        $this->assertCount(2, $this->claudeCalls(), 'Claude was never asked what Alice wanted.');
        $this->assertCount(2, $this->played);
        // The phrase itself, and then the question it took back, when its turn came.
        $this->assertSame(
            [['user' => '555', 'reason' => 'the stop phrase'], ['user' => '555', 'reason' => 'the stop phrase']],
            $this->contexts('Not answering'),
        );
        $this->assertSame(['user' => '666', 'by' => '555', 'reason' => 'the stop phrase'], array_slice($this->contexts('Stopped answering')[0], 0, 3));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testTheStopPhraseStopsAnAnswerOnce(): void
    {
        // The bot is speaking the first sentence, and Claude is still writing the second.
        $this->playing = (new Deferred())->promise();
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past four. ', 'Time for a cup of tea.'), 'FAKE_CLAUDE_PAUSE' => '10']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => count($this->played) === 1, 'the first sentence to be spoken');
        $writing = $this->claudeCalls()[0]['pid'];

        $this->hears($vc, '666', self::STOP);
        $this->hears($vc, '666', self::STOP);

        // The second time there is nothing left to stop, though the answer isn't over: Claude is still writing it.
        $this->assertCount(1, $this->contexts('Stopped answering'));
        $this->assertSame([$this->played[0]], $this->cutOff);
        // Something of it was heard, so Claude writes it to its end, and it is posted, as when it is talked over.
        $this->assertTrue($this->isRunning($writing));
        touch($this->claudeResume);
        $this->waitUntil(fn () => $this->sent !== [], 'the answer to be posted');
        $this->runFor(0.3);

        $this->assertSame(['> **Alice:** ' . self::QUESTION . "\nIt is a quarter past four. Time for a cup of tea."], $this->sent);
        $this->assertCount(1, $this->played, 'The rest of it is not spoken.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testTheStopPhraseHasNothingToStopOnceAnAnswerIsOver(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to be spoken');

        $this->hears($vc, '666', self::STOP);

        $this->assertSame([], $this->logged('Stopped answering'));
        $this->assertSame([], $this->cutOff);
        $this->assertSame([['user' => '666', 'reason' => 'the stop phrase']], $this->contexts('Not answering'));
    }

    public function testANewQuestionReplacesTheAnswerClaudeIsStillWriting(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is', ' a quarter past four.'), 'FAKE_CLAUDE_PAUSE' => '10']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->logged('Claude started answering') !== [], 'Claude to start writing');

        // She asks something else before the bot has said a word: that is what she wants to know now.
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is Thursday.'), 'FAKE_CLAUDE_PAUSE' => '0']);
        $this->ask($vc, '555', 'Claude, what day is it?');
        $this->waitUntil(fn () => $this->played !== [], 'the new answer to be spoken');
        $this->runFor(0.3);

        $this->assertSame(['It is Thursday.'], array_map(file_get_contents(...), $this->played), 'The old answer was never spoken.');
        $this->assertSame(['> **Alice:** Claude, what day is it?' . "\nIt is Thursday."], $this->sent);
        $stopped = $this->contexts('Stopped answering');
        $this->assertCount(1, $stopped);
        $this->assertSame(['user' => '555', 'by' => '555', 'reason' => 'a new question'], array_slice($stopped[0], 0, 3));
        $this->assertFalse($stopped[0]['spoken']);
        $this->assertSame([], $this->logged('Not answering'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testWhatIsNoQuestionToTheBotReplacesNothing(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is', ' a quarter past four.'), 'FAKE_CLAUDE_PAUSE' => '10']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->logged('Claude started answering') !== [], 'Claude to start writing');

        // While she waits, Alice says something to Bob, coughs, makes a sound whisper hears nothing in, and only says the bot's name.
        $this->hears($vc, '555', 'Let us see what it says.');
        $this->hears($vc, '555', '(coughs)');
        $this->hears($vc, '555', '');
        $this->hears($vc, '555', 'Claude.');

        touch($this->claudeResume);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');

        $this->assertSame([self::ANSWER], array_map(file_get_contents(...), $this->played));
        $this->assertSame([], $this->logged('Stopped answering'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testSomeoneElsesQuestionDoesNotReplaceTheAnswerClaudeIsWriting(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is', ' a quarter past four.'), 'FAKE_CLAUDE_PAUSE' => '10']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->logged('Claude started answering') !== [], 'Claude to start writing');

        // Bob asks something too. Alice still gets her answer, and then he gets his.
        $this->hears($vc, '666', 'Claude, what day is it?');
        touch($this->claudeResume);
        $this->waitUntil(fn () => count($this->sent) === 2, 'both answers');

        $this->assertStringStartsWith('> **Alice:** ', $this->sent[0]);
        $this->assertStringStartsWith('> **Bob:** ', $this->sent[1]);
        $this->assertSame([], $this->logged('Stopped answering'));
        $this->assertSame([], $this->logged('Not answering'));
    }

    public function testANewQuestionReplacesTheirQuestionThatStillWaitsForItsTurn(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $speaking = new Deferred();
        $this->playing = $speaking->promise();
        $this->ask($vc, '666', 'Hey Claude, are you there?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to Bob to be spoken');

        // Alice asks something while the bot is answering Bob, and then something else.
        $this->hears($vc, '555', self::QUESTION);
        $this->hears($vc, '555', 'Claude, what day is it?');

        // The answer to Bob is not hers to stop: it is spoken to its end.
        $this->assertSame([], $this->cutOff);
        $this->assertSame([], $this->logged('Stopped answering'));
        $this->playing = null;
        $speaking->resolve(null);
        $this->waitUntil(fn () => count($this->sent) === 2, 'the answer to Alice');
        $this->runFor(0.3);

        // Only what she asked last is answered.
        $this->assertSame('> **Alice:** Claude, what day is it?' . "\n" . self::ANSWER, $this->sent[1]);
        $this->assertCount(2, $this->claudeCalls());
        $this->assertSame([['user' => '555', 'reason' => 'a new question']], $this->contexts('Not answering'));
        $this->assertSame([], $this->cutOff);
    }

    public function testAnAnswerThatWasCutWhileItWasSpokenIsInWhatClaudeIsGivenForTheNextQuestion(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        // The sentence the bot is speaking is a long one.
        $this->playing = (new Deferred())->promise();
        $this->ask($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to be spoken');

        // She talks over it with her next question: that cuts it, as it did before she had said what it was.
        $this->playing = null;
        $this->ask($vc, '555', 'Claude, I meant in Lisbon.');

        $this->assertSame([$this->played[0]], $this->cutOff);
        $this->assertCount(1, $this->logged('Interrupted'));
        $this->assertSame([], $this->logged('Stopped answering'), 'It was stopped already when her words were known.');
        // Claude knows what it had said: the answer was posted and is part of the call, though it was cut.
        $this->assertStringContainsString('] Claude: ' . self::ANSWER . "\n", $this->claudeCalls()[1]['prompt']);
        $this->assertCount(2, $this->sent);
    }

    public function testALineSaidWhileClaudeIsWritingStandsBeforeTheAnswerInTheTranscript(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is', ' a quarter past four.'), 'FAKE_CLAUDE_PAUSE' => '10']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->logged('Claude started answering') !== [], 'Claude to start writing');
        $this->hears($vc, '666', 'Then we have an hour left.');
        touch($this->claudeResume);
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        // In the order it was said, not the order the bot got round to it.
        $this->assertSame(
            'Alice: ' . self::QUESTION . "\nBob: Then we have an hour left.\nClaude: " . self::ANSWER . "\n",
            $this->untimed($this->transcript($session)),
        );
    }

    public function testWhatIsSaidIsTranscribedOneAfterTheOtherInTheOrderItEnded(): void
    {
        $whisper = "{$this->recordings}/whisper.log";
        $this->setProcessEnv(['FAKE_WHISPER_LOG' => $whisper, 'FAKE_WHISPER_OUTPUT' => 'Sounds good.']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Alice and Bob say something at the same time, and whisper takes long over what Alice said.
        $this->speakAndWait($vc, '555', '666');
        $this->runFor(0.3);

        // What Bob said waits for it: a whisper-cli for every sentence at once would share one graphics card.
        $this->assertStringContainsString('/utterance-1.wav', file_get_contents($whisper));
        $this->assertCount(2, glob("{$session->directory}/utterances/*"));

        $this->transcribe();
        $this->waitUntil(fn () => count($this->logged('Transcribed')) === 2, 'both to be transcribed');

        $this->assertStringContainsString('/utterance-2.wav', file_get_contents($whisper));
        $this->assertSame(['555', '666'], array_column($this->logged('Transcribed'), 'user'));
        $this->assertSame("Alice: Sounds good.\nBob: Sounds good.\n", $this->untimed($this->transcript($session)));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAQuestionIsNotAnsweredOnceWhoeverAskedItOptedOutWhileItWaitedForItsTurn(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $speaking = new Deferred();
        $this->playing = $speaking->promise();
        $this->ask($vc, '666', 'Hey Claude, are you there?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to Bob to be spoken');

        // What Alice asks is transcribed at once, and waits its turn. Then she opts out.
        $this->hears($vc, '555', self::QUESTION);
        VoiceSession::optOut('555');
        $this->playing = null;
        $speaking->resolve(null);
        $this->waitUntil(fn () => $this->logged('Not answering') !== [], 'her question to have its turn');
        $this->runFor(0.3);

        $this->assertSame([['user' => '555', 'reason' => 'they opted out']], $this->contexts('Not answering'));
        $this->assertCount(1, $this->claudeCalls(), 'Claude was not asked.');
        $this->assertCount(1, $this->sent);
        $this->assertCount(1, $this->played);
        // What she said before she opted out stays in the transcript, as the docs say.
        $this->assertStringContainsString('] Alice: ' . self::QUESTION . "\n", $this->transcript($session));
    }

    public function testAQuestionIsNotAnsweredOnceTheCallStoppedWhileItWaitedForItsTurn(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->playing = (new Deferred())->promise();
        $this->ask($vc, '666', 'Hey Claude, are you there?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to Bob to be spoken');

        $this->hears($vc, '555', self::QUESTION);
        $this->playing = null;
        $session->stop();
        $this->callEnds($session);

        $this->assertSame([['user' => '555', 'reason' => 'the session stopped']], $this->contexts('Not answering'));
        $this->assertSame([], array_filter($this->sent, fn (string $sent) => str_starts_with($sent, '> **Alice:**')), 'It was not answered.');
        // It is in the transcript, and so in the summary.
        $this->assertStringContainsString('] Alice: ' . self::QUESTION . "\n", $this->transcript($session));
    }

    public function testWhatWasSaidAndTranscribedBeforeForgetIsTakenOutOfTheTranscriptThoughItsTurnHasNotCome(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->inCall('555', '666');
        $speaking = new Deferred();
        $this->playing = $speaking->promise();
        $this->ask($vc, '666', 'Hey Claude, are you there?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to Bob to be spoken');

        // It is transcribed while the bot is still answering Bob, with who was there when it was said.
        $this->hears($vc, '555', 'Claude, we are going to Porto.');
        VoiceSession::forget(['555', '666']);

        $this->assertStringNotContainsString('Porto', $this->transcript($session));
        $this->assertStringNotContainsString('are you there', $this->transcript($session));

        // Its turn comes: Claude is not given what was taken out of the call, and it is not posted as a question.
        $this->playing = null;
        $speaking->resolve(null);
        $this->waitUntil(fn () => $this->logged('Not answering') !== [], 'her turn to come');
        $this->runFor(0.3);

        $this->assertSame([['user' => '555', 'reason' => 'it was forgotten']], $this->contexts('Not answering'));
        $this->assertCount(1, $this->claudeCalls());
        $this->assertStringNotContainsString('Porto', implode("\n", $this->sent));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAQuestionThatWhisperHadNotWrittenWhenForgetCameIsAnswered(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->inCall('555', '666');

        // Nothing of it was in the transcript to take out: it gets its line after, and is not remembered.
        $this->speakAndWait($vc, '555');
        VoiceSession::forget(['555', '666']);
        $this->transcribe();
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        $this->assertCount(1, $this->claudeCalls());
        $this->assertSame([], $this->logged('Not answering'));
        $this->assertStringContainsString('] Alice: ', $this->transcript($session));
    }

    public function testAQuestionSaidAfterForgetIsAnswered(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->inCall('555', '666');
        $this->ask($vc, '666', 'Hey Claude, are you there?');
        VoiceSession::forget(['555', '666']);

        // Only what was said before it is forgotten.
        $this->ask($vc, '555', self::QUESTION);

        $this->assertCount(2, $this->claudeCalls());
        $this->assertSame([], $this->logged('Not answering'));
    }

    public function testClaudeStartingOnAnAnswerAsItIsStoppedIsNotAStart(): void
    {
        // Claude takes long to start, and its first words come as it is ended.
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '30', 'FAKE_CLAUDE_LAST_WORDS' => self::claudeText('It is a quarter past four. ')]);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->claudeCalls() !== [], 'Claude to be asked');
        $asked = $this->claudeCalls()[0]['pid'];
        // The summary at the end of the test is not to take as long.
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0', 'FAKE_CLAUDE_LAST_WORDS' => '']);

        $this->hears($vc, '555', self::STOP);
        $this->waitUntil(fn () => ! $this->isRunning($asked), 'Claude Code to end', 3.0);
        $this->runFor(0.3);

        $this->assertSame([], $this->logged('Claude started answering'), 'It had stopped answering by then.');
        $this->assertSame([], $this->played);
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testWhatClaudeHadStillWrittenWhenItsAnswerWasStoppedIsNotSpoken(): void
    {
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is', ' a quarter past four.'),
            'FAKE_CLAUDE_PAUSE' => '30',
            // What it had written and not handed over yet arrives when it is ended: a whole sentence, and more.
            'FAKE_CLAUDE_LAST_WORDS' => self::claudeText(' a quarter past four. And it is Thursday today, all day long.'),
        ]);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->logged('Claude started answering') !== [], 'Claude to start writing');
        $writing = $this->claudeCalls()[0]['pid'];

        $this->hears($vc, '555', self::STOP);
        $this->waitUntil(fn () => ! $this->isRunning($writing), 'Claude Code to end', 3.0);
        // The summary at the end of the test is not to take as long.
        $this->setProcessEnv(['FAKE_CLAUDE_PAUSE' => '0', 'FAKE_CLAUDE_LAST_WORDS' => '']);
        $this->runFor(0.5);

        $this->assertSame([], $this->played, 'The answer was stopped: nothing of it is spoken, whenever it arrives.');
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAnAnswerClaudeHadWrittenToItsEndIsPostedThoughItWasStoppedBeforeItsFirstWord(): void
    {
        // Piper takes long over the first sentence: Claude has written its answer, and nobody has heard any of it.
        $this->setProcessEnv(['FAKE_PIPER_DELAY' => '2']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->sent !== [], 'the answer to be posted');
        $this->assertSame([], $this->played);

        $this->hears($vc, '555', self::STOP);
        $this->runFor(2.5);

        // It is not spoken. What was posted when Claude had written it stays, and so does its line in the transcript.
        $this->assertSame([], $this->played);
        $this->assertSame(['> **Alice:** ' . self::QUESTION . "\n" . self::ANSWER], $this->sent);
        $this->assertStringContainsString('] Claude: ' . self::ANSWER . "\n", $this->transcript($session));
        $this->assertFalse($this->contexts('Stopped answering')[0]['spoken']);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testTheStopPhraseOfSomeoneWhoOptedOutIsNotHeard(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        VoiceSession::optOut('666');
        $this->playing = (new Deferred())->promise();
        $this->ask($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to Alice to be spoken');

        // Nothing Bob says is recorded or transcribed, so the bot doesn't know that he said it.
        $this->says($vc, '666', self::STOP);
        $this->runFor(1.5);

        $this->assertSame([], $this->cutOff);
        $this->assertCount(1, $this->logged('Transcribed'));
        $this->assertSame([], $this->logged('Stopped answering'));
    }

    public function testTheLeavePhraseEndsTheCallWhileClaudeIsStillWriting(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is', ' a quarter past four.'), 'FAKE_CLAUDE_PAUSE' => '30']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->logged('Claude started answering') !== [], 'Claude to start writing');
        $writing = $this->claudeCalls()[0]['pid'];

        // Bob ends the call. It doesn't wait for an answer that nobody has heard a word of.
        $this->hears($vc, '666', self::LEAVE);
        $this->waitUntil(fn () => ! $this->isRunning($writing), 'Claude Code to end', 3.0);
        // The summary is Claude's too: it is not held back.
        touch($this->claudeResume);
        $this->callEnds($session);

        $this->assertSame(['Okay.'], array_map(file_get_contents(...), $this->played));
        $this->assertSame([['user' => '666']], $this->contexts('Ended by the leave phrase'));
        $this->assertSame('Bob ended the call by voice.', $this->sent[0]);
        $this->assertSame([], array_filter($this->sent, fn (string $sent) => str_starts_with($sent, '> **')), 'The answer was not posted.');
        $stopped = $this->contexts('Stopped answering');
        $this->assertCount(1, $stopped);
        $this->assertSame(['user' => '555', 'by' => '666', 'reason' => 'the leave phrase'], array_slice($stopped[0], 0, 3));
        $this->assertFalse($stopped[0]['spoken']);
        $this->assertLessThan(5000, $stopped[0]['ms'], 'Since he stopped saying it.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testNothingThatWaitedIsAnsweredOnceSomeoneSaidTheLeavePhrase(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->playing = (new Deferred())->promise();
        $this->ask($vc, '666', 'Hey Claude, are you there?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to Bob to be spoken');

        // Alice and Carol each ask something, and then Alice ends the call.
        $this->hears($vc, '555', self::QUESTION);
        $this->hears($vc, '777', 'Claude, what day is it?');
        // "Okay." is a long sentence here: the call has not stopped while the questions have their turns.
        $okay = new Deferred();
        $this->playing = $okay->promise();
        $this->hears($vc, '555', self::LEAVE);
        $this->waitUntil(fn () => count($this->played) === 2, 'okay to be spoken');
        $this->assertSame($session, VoiceSession::forGuild(self::GUILD_ID));

        // What is said meanwhile is in the transcript, and not answered either.
        $this->hears($vc, '666', 'Claude, wait.');
        $this->assertSame(
            [['user' => '555', 'reason' => 'the session stopped'], ['user' => '777', 'reason' => 'the session stopped'], ['user' => '666', 'reason' => 'the session stopped']],
            $this->contexts('Not answering'),
        );

        $this->playing = null;
        $okay->resolve(null);
        $this->callEnds($session);

        $this->assertSame([$this->played[0]], $this->cutOff, 'The answer to Bob was cut off for it.');
        $this->assertSame('Okay.', file_get_contents($this->played[1]));
        $this->assertCount(2, $this->played);
        $this->assertCount(1, array_filter($this->sent, fn (string $sent) => str_starts_with($sent, '> **')), 'Only Bob was answered.');
        $this->assertSame([['user' => '555']], $this->contexts('Ended by the leave phrase'));
        $this->assertSame(1, substr_count(implode("\n", $this->sent), 'Alice ended the call by voice.'));
        $this->assertStringContainsString('] Bob: Claude, wait.' . "\n", $this->transcript($session));
        $this->assertSame([], $this->loggedProblems());
    }

    /**
     * Someone says something, and whisper has written it: the bot knows what it is, whatever it is still doing.
     */
    private function hears(VoiceClient $vc, string $userId, string $text): void
    {
        $transcribed = count($this->logged('Transcribed'));
        $this->says($vc, $userId, $text);
        $this->waitUntil(fn () => count($this->logged('Transcribed')) === $transcribed + 1, 'it to be transcribed');
    }

    private function callEnds(VoiceSession $session): void
    {
        $this->waitUntil(fn () => ! in_array($session, VoiceSession::unfinished(), true), 'the call to be over');
    }
}
