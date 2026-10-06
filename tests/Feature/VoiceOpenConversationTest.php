<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Settings\GuildSettings;
use App\Voice\VoiceSession;
use Discord\Voice\VoiceClient;
use Monolog\Logger;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use Tests\Fixtures\ManualTimers;

use function React\Async\await;

/**
 * Saying the wake word opens a conversation: the bot keeps answering that person, until they say
 * the stop phrase or are quiet for a minute. The bot's timers only run out when a test says so.
 *
 * Alice, Bob and Carol are 555, 666 and 777. Whatever whisper hears in a test is what the last one
 * to speak said, so nothing here lets two people talk before their first sentence is transcribed.
 */
final class VoiceOpenConversationTest extends VoiceTestCase
{
    private const string ANSWER = 'It is a quarter past four.';

    private ManualTimers $timers;

    protected function loop(): LoopInterface
    {
        return $this->timers ??= new ManualTimers();
    }

    /**
     * The timer that ends an utterance once it fell silent is the bot's, so it only runs out here.
     */
    protected function waitUntil(callable $condition, string $what, float $timeout = 10.0): void
    {
        parent::waitUntil(function () use ($condition) {
            $this->timers->elapse(0.05);

            return $condition();
        }, $what, $timeout);
    }

    public function testKeepsAnsweringSomeoneAfterTheyMentionedTheWakeWord(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hi, Claude. How are you doing?');
        $this->ask($vc, '555', 'Nothing much. What about you?');
        // Saying the wake word again is answered like anything else, and doesn't open a second conversation.
        $this->ask($vc, '555', 'Claude, tell me a joke.');

        $this->assertSame([
            '> **Alice:** Hi, Claude. How are you doing?' . "\n" . self::ANSWER,
            '> **Alice:** Nothing much. What about you?' . "\n" . self::ANSWER,
            '> **Alice:** Claude, tell me a joke.' . "\n" . self::ANSWER,
        ], $this->sent);
        $this->assertStringContainsString("Alice: Nothing much. What about you?\n\nAlice is talking to you.", $this->claudeCalls()[1]['prompt']);
        $this->assertSame([['user' => '555']], $this->contexts('Conversation opened'));
        $this->assertSame([], $this->logged('Conversation closed'));

        // Each answer starts the minute again: one timer is left, not one for every answer.
        $this->waitUntil(fn () => $this->quietTimers() !== [], 'the conversation to be quiet');
        $this->runFor(0.5);
        $this->assertSame([60.0], $this->quietTimers());
        $this->assertSame([], $this->loggedProblems());
    }

    public function testSomeoneElsesSentenceNeedsTheWakeWordAndOpensTheirOwnConversation(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        // Alice's conversation is not Bob's.
        $this->hearsNoAnswer($vc, $session, '666', 'Sounds good.');

        $this->ask($vc, '666', 'Claude, what about me?');
        // Now both are open at the same time.
        $this->ask($vc, '555', 'And what is the date?');
        $this->ask($vc, '666', 'And me?');
        $this->hearsNoAnswer($vc, $session, '777', 'Count me in.');

        $this->assertSame([
            '> **Alice:** Hey Claude, what time is it?' . "\n" . self::ANSWER,
            '> **Bob:** Claude, what about me?' . "\n" . self::ANSWER,
            '> **Alice:** And what is the date?' . "\n" . self::ANSWER,
            '> **Bob:** And me?' . "\n" . self::ANSWER,
        ], $this->sent);
        $this->assertSame([['user' => '555'], ['user' => '666']], $this->contexts('Conversation opened'));
        // Everyone's lines are in the transcript, answered or not.
        $this->assertStringContainsString('] Carol: Count me in.', $this->transcript($session));
    }

    public function testTheStopPhraseClosesTheConversationAndTheBotSaysOkay(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to be spoken');
        $asked = count($this->claudeCalls());

        // Whisper punctuates it, and it contains the wake word, which doesn't make it a question.
        $this->say($vc, '555', 'Stop, Claude.');
        $this->waitUntil(fn () => count($this->played) === 2, 'okay to be spoken');

        // A fixed sentence in the call's voice: no Claude request, and no trace of it in the chat or the transcript.
        $this->assertSame("{$session->directory}/claude-3.ogg", $this->played[1]);
        $this->assertSame('Okay.', file_get_contents($this->played[1]));
        $this->assertSame($asked, count($this->claudeCalls()), 'Claude was not asked.');
        $this->assertSame(['> **Alice:** Hey Claude, what time is it?' . "\n" . self::ANSWER], $this->sent);
        $this->assertStringEndsWith("] Alice: Stop, Claude.\n", $this->transcript($session));
        $this->assertStringNotContainsString('Okay', $this->transcript($session));
        $this->assertSame(1, $this->usage()['answers'], 'Okay is not an answer.');
        $this->assertSame([['user' => '555', 'reason' => 'stop phrase']], $this->contexts('Conversation closed'));
        $this->assertSame([], $this->quietTimers(), 'Nothing is left to close.');

        // The next sentence needs the wake word again.
        $this->hearsNoAnswer($vc, $session, '555', 'Thanks, bye.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testTheStopPhraseOnlyClosesTheConversationOfWhoSaidIt(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->ask($vc, '666', 'Claude, what is the date?');
        $this->waitUntil(fn () => count($this->played) === 2, 'both answers to be spoken');

        $this->say($vc, '666', 'Stop Claude.');
        $this->waitUntil(fn () => count($this->played) === 3, 'okay to be spoken');

        $this->assertSame([['user' => '666', 'reason' => 'stop phrase']], $this->contexts('Conversation closed'));
        // Alice is still being answered, and Bob no longer is.
        $this->ask($vc, '555', 'Are you still there?');
        $this->hearsNoAnswer($vc, $session, '666', 'Anything else?');
    }

    public function testTheStopPhraseIsNeverSentToClaudeWhenNothingWasOpen(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // It mentions the wake word, but it isn't a question, and there is nothing to close: no "Okay." either.
        $this->hearsNoAnswer($vc, $session, '777', 'Stop Claude.');

        $this->assertFileDoesNotExist($this->claudeLog, 'Claude was not asked.');
        $this->assertSame([], $this->played);
        $this->assertSame([], $this->logged('Conversation closed'));
        $this->assertStringEndsWith("] Carol: Stop Claude.\n", $this->transcript($session));

        // It leaves nothing behind that would answer her next sentence.
        $this->hearsNoAnswer($vc, $session, '777', 'Sorry about that.');
        $this->ask($vc, '777', 'Claude, what time is it?');
    }

    public function testTheDefaultStopPhraseFollowsTheServersWakeWord(): void
    {
        (new GuildSettings(new Logger('test')))->save(self::GUILD_ID, [...GuildSettings::DEFAULTS, 'wake_word' => 'computer'], '555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('stop computer', $session->stopPhrase);

        $this->ask($vc, '555', 'Hey computer, what time is it?');
        // The phrase of a server with another wake word closes nothing here: this is only a sentence.
        $this->ask($vc, '555', 'Stop Claude, please.');
        $this->assertSame([], $this->logged('Conversation closed'));

        $this->say($vc, '555', 'Okay, stop computer.');
        $this->waitUntil(fn () => count($this->played) === 3, 'okay to be spoken');
        $this->assertSame([['user' => '555', 'reason' => 'stop phrase']], $this->contexts('Conversation closed'));
    }

    public function testTheDefaultStopPhraseIsHeardForEverySpellingOfTheWakeWord(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => 'claude, cloud, claud']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('stop claude, stop cloud, stop claud', $session->stopPhrase);

        // Whisper writes "Claude" as "Cloud" and "Claud" when it mishears it, in the stop phrase too.
        $this->ask($vc, '555', 'Hey Cloud, what time is it?');
        $this->say($vc, '555', 'Stop, cloud.');
        $this->waitUntil(fn () => count($this->played) === 2, 'okay to be spoken');
        $this->assertSame([['user' => '555', 'reason' => 'stop phrase']], $this->contexts('Conversation closed'));

        $this->ask($vc, '555', 'Claud, are you there?');
        $this->say($vc, '555', 'Stop Claud.');
        $this->waitUntil(fn () => count($this->played) === 4, 'okay to be spoken');
        $this->assertCount(2, $this->logged('Conversation closed'));
    }

    public function testWithoutAWakeWordInTheEnvAServerHearsClaudAsWellAsClaude(): void
    {
        // Whisper writes "Claude" as "Claud" often enough to be a spelling of the default, and no one says "claud".
        $this->setEnv(['VOICE_WAKE_WORD' => 'claude']);
        unset($_ENV['VOICE_WAKE_WORD']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('claude, claud', $session->wakeWord);
        $this->assertSame('stop claude, stop claud', $session->stopPhrase);

        $this->ask($vc, '555', 'Claud, what time is it?');
        $this->assertSame([['user' => '555']], $this->contexts('Conversation opened'));

        $this->say($vc, '555', 'Stop, claud.');
        $this->waitUntil(fn () => count($this->played) === 2, 'okay to be spoken');
        $this->assertSame([['user' => '555', 'reason' => 'stop phrase']], $this->contexts('Conversation closed'));

        // "Cloud" is not a spelling of the default: people talk about the cloud.
        $this->hearsNoAnswer($vc, $session, '666', 'The cloud is down.');
    }

    public function testTheStopPhraseInTheEnvReplacesTheDefaultForEveryServer(): void
    {
        $this->setEnv(['VOICE_STOP_PHRASE' => ' para claude ']);
        // This server has a wake word of its own, and the same stop phrase as every other.
        (new GuildSettings(new Logger('test')))->save(self::GUILD_ID, [...GuildSettings::DEFAULTS, 'wake_word' => 'computer'], '555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('para claude', $session->stopPhrase);

        $this->ask($vc, '555', 'Hey computer, what time is it?');
        $this->ask($vc, '555', 'Stop computer.');
        $this->assertSame([], $this->logged('Conversation closed'), 'The default no longer closes it.');

        $this->say($vc, '555', 'Para, Claude.');
        $this->waitUntil(fn () => count($this->played) === 3, 'okay to be spoken');
        $this->assertSame([['user' => '555', 'reason' => 'stop phrase']], $this->contexts('Conversation closed'));
    }

    public function testClosesAfterAMinuteOfQuiet(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => $this->quietTimers() !== [], 'the conversation to be quiet');

        // A minute counts from when the bot finished speaking.
        $this->assertSame([60.0], $this->quietTimers());
        $this->assertSame([], $this->logged('Conversation closed'));
        $this->timers->elapse(60.0);

        $this->assertSame([['user' => '555', 'reason' => 'quiet']], $this->contexts('Conversation closed'));
        $this->assertCount(1, $this->played, 'Nothing is said when it closes.');
        $this->hearsNoAnswer($vc, $session, '555', 'Anything else?');
    }

    public function testOnlyTheConversationOfWhoWasQuietCloses(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => $this->quietTimers() !== [], 'the conversation to be quiet');

        // Bob's conversation is open too, but he is still being answered, so only Alice has a minute running out.
        $this->setProcessEnv(['FAKE_CLAUDE_PAUSE' => '10']);
        $this->say($vc, '666', 'Claude, what is the date?');
        $this->waitUntil(fn () => count($this->claudeCalls()) === 2, 'Claude to be asked');
        $this->setProcessEnv(['FAKE_CLAUDE_PAUSE' => '0']);
        $this->assertSame(1, $this->timers->elapse(60.0));
        $this->assertSame([['user' => '555', 'reason' => 'quiet']], $this->contexts('Conversation closed'));

        touch($this->claudeResume);
        $this->waitUntil(fn () => count($this->sent) === 2, 'Bob to be answered');
        $this->hearsNoAnswer($vc, $session, '555', 'Anything else?');
        $this->ask($vc, '666', 'And me?');
    }

    public function testDoesNotCloseWhileTheyAreStillSpeaking(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => $this->quietTimers() !== [], 'the conversation to be quiet');

        // Alice is saying something when the minute is over: it ends after the timer ran out, and is still answered.
        $this->say($vc, '555', 'And what is the date?');
        $this->assertSame(1, $this->timers->elapse(60.0));

        $this->assertSame([], $this->logged('Conversation closed'));
        $this->assertSame([60.0], $this->quietTimers(), 'A minute from now, unless it is answered first.');
        $this->waitUntil(fn () => count($this->sent) === 2, 'the answer');
        $this->assertSame('> **Alice:** And what is the date?' . "\n" . self::ANSWER, $this->sent[1]);
    }

    public function testClosesOnceWhatTheySaidWhenTheMinuteRanOutTurnsOutTooShortToCount(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => $this->quietTimers() !== [], 'the conversation to be quiet');

        // A cough: dropped once it ends, so nothing answers it and nothing starts the minute again.
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 0.2);
        $this->assertSame(1, $this->timers->elapse(60.0));
        $this->waitUntil(fn () => glob("{$session->directory}/utterances/*") === [], 'the cough to be dropped');
        $this->assertSame([], $this->logged('Conversation closed'));

        $this->assertSame(1, $this->timers->elapse(60.0));
        $this->assertSame([['user' => '555', 'reason' => 'quiet']], $this->contexts('Conversation closed'));
    }

    public function testDoesNotCloseWhileAnAnswerIsBeingSpoken(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => $this->quietTimers() !== [], 'the conversation to be quiet');

        // The minute of the first answer is over while the second one is still being spoken.
        $speaking = new Deferred();
        $this->playing = $speaking->promise();
        $this->ask($vc, '555', 'And what is the date?');
        $this->waitUntil(fn () => count($this->played) === 2, 'the answer to be spoken');
        $this->assertSame(1, $this->timers->elapse(60.0));

        $this->assertSame([], $this->logged('Conversation closed'));
        $this->assertSame([], $this->quietTimers(), 'The minute counts from when the bot finished speaking.');

        $speaking->resolve(null);
        $this->waitUntil(fn () => $this->quietTimers() !== [], 'the conversation to be quiet again');
        $this->assertSame([60.0], $this->quietTimers());
        $this->ask($vc, '555', 'Thanks.');
    }

    public function testAnswersASentenceSaidBeforeTheMinuteWhoseTurnComesAfterIt(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => $this->quietTimers() !== [], 'the conversation to be quiet');

        // Claude is slow to answer Bob, and Alice has something to say meanwhile: it waits for its turn.
        $this->setProcessEnv(['FAKE_CLAUDE_PAUSE' => '10']);
        $this->say($vc, '666', 'Claude, what is the date?');
        $this->waitUntil(fn () => count($this->claudeCalls()) === 2, 'Claude to be asked');
        $this->setProcessEnv(['FAKE_CLAUDE_PAUSE' => '0']);
        $this->say($vc, '555', 'And what about the weather?');
        $this->waitUntil(fn () => count($this->logged('Utterance ended')) === 3, 'Alice to finish speaking');

        // A minute after her first answer is over, and what she said has not had its turn.
        $this->assertSame(1, $this->timers->elapse(60.0));
        $this->assertSame([], $this->logged('Conversation closed'));

        touch($this->claudeResume);
        $this->waitUntil(fn () => count($this->sent) === 3, 'both answers');
        $this->assertSame('> **Alice:** And what about the weather?' . "\n" . self::ANSWER, $this->sent[2]);
        $this->assertSame([], $this->logged('Conversation closed'));
    }

    public function testAStopPhraseSaidOverAnAnswerToSomeoneElseWaitsForItsTurn(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '666', 'Hey Claude, are you there?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to Bob to be spoken');
        $speaking = new Deferred();
        $this->playing = $speaking->promise();
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 2, 'the answer to Alice to be spoken');

        // Bob, whose conversation is open too, closes it while the bot is answering Alice.
        $this->say($vc, '666', 'Stop, Claude.');
        $this->waitUntil(fn () => count($this->logged('Utterance ended')) === 3, 'Bob to finish speaking');
        $this->runFor(0.5);

        // Only Alice can interrupt the answer to her: it is spoken to the end first.
        $this->assertSame([], $this->cutOff);
        $this->assertSame([], $this->logged('Conversation closed'));
        $this->assertCount(2, $this->played);

        $speaking->resolve(null);
        $this->waitUntil(fn () => count($this->played) === 3, 'okay to be spoken');
        $this->assertSame([['user' => '666', 'reason' => 'stop phrase']], $this->contexts('Conversation closed'));
    }

    public function testAStopPhraseSaidOverTheirOwnAnswerStopsTheBotAndClosesTheConversation(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        // The sentence the bot is speaking is a long one.
        $this->playing = (new Deferred())->promise();
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to be spoken');

        // Alice doesn't wait for it to end. As she is the one being answered, talking over it stops the bot.
        $this->playing = null;
        $this->say($vc, '555', 'Stop, Claude.');

        $this->assertSame([$this->played[0]], $this->cutOff);
        $this->assertCount(1, $this->logged('Interrupted'));

        // Her stop phrase has its turn as soon as she has said it, and not once the answer would have been spoken.
        $this->waitUntil(fn () => count($this->played) === 2, 'okay to be spoken');
        $this->assertSame([['user' => '555', 'reason' => 'stop phrase']], $this->contexts('Conversation closed'));
        $this->assertSame('Okay.', file_get_contents($this->played[1]));
        $this->assertCount(1, $this->sent, 'Nothing else was answered.');
    }

    public function testClosesWhenTheyOptOutAndDoesNotOpenAgainWhenTheyOptBackIn(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => $this->quietTimers() !== [], 'the conversation to be quiet');

        VoiceSession::optOut('555');

        $this->assertSame([['user' => '555', 'reason' => 'opted out']], $this->contexts('Conversation closed'));
        $this->assertSame([], $this->quietTimers(), 'Nothing is left to close.');

        VoiceSession::optIn('555');
        $this->hearsNoAnswer($vc, $session, '555', 'Anything else?');
        $this->assertCount(1, $this->logged('Conversation opened'));
    }

    public function testHasNoConversationsWithoutAWakeWord(): void
    {
        // Everything is answered already, so there is nothing to open and nothing to stop.
        $this->setEnv(['VOICE_WAKE_WORD' => '', 'VOICE_STOP_PHRASE' => 'para claude']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('', $session->stopPhrase);

        $this->ask($vc, '555', 'Para Claude, what time is it?');
        $this->ask($vc, '555', 'And what is the date?');

        $this->assertSame([
            '> **Alice:** Para Claude, what time is it?' . "\n" . self::ANSWER,
            '> **Alice:** And what is the date?' . "\n" . self::ANSWER,
        ], $this->sent);
        $this->waitUntil(fn () => count($this->played) === 2, 'both answers to be spoken');
        $this->assertSame([self::ANSWER, self::ANSWER], array_map(file_get_contents(...), $this->played));
        $this->assertSame([], $this->logged('Conversation opened'));
        $this->assertSame([], $this->quietTimers());
    }

    public function testHasNoConversationsWhenTheWakeWordHasNoSpellingLeft(): void
    {
        // Only commas: like an empty wake word, everything is answered.
        $this->setEnv(['VOICE_WAKE_WORD' => ' , ']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('', $session->stopPhrase);

        $this->ask($vc, '555', 'What time is it?');

        $this->assertSame([], $this->logged('Conversation opened'));
        $this->assertSame([], $this->quietTimers());
    }

    public function testClosesTheConversationEvenWhenOkayCannotBeSpoken(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to be spoken');

        $this->setProcessEnv(['FAKE_PIPER_FAILS_ON' => 'Okay.']);
        $this->say($vc, '555', 'Stop, Claude.');
        $this->waitUntil(fn () => $this->loggedProblems() !== [], 'the warning');

        $this->assertCount(1, $this->played);
        $this->assertCount(1, $this->loggedProblems());
        $this->assertStringStartsWith('Could not say okay: ', $this->loggedProblems()[0]);
        $this->assertSame([['user' => '555', 'reason' => 'stop phrase']], $this->contexts('Conversation closed'));
        $this->assertSame('555', $this->logged($this->loggedProblems()[0])[0]['user']);
        $this->hearsNoAnswer($vc, $session, '555', 'Anything else?');
    }

    public function testDoesNotSayOkayInACallThatEnded(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to be spoken');

        // The call ends while "Okay." is being made.
        $this->setProcessEnv(['FAKE_PIPER_DELAY' => '0.5']);
        $this->say($vc, '555', 'Stop, Claude.');
        $this->waitUntil(fn () => $this->logged('Conversation closed') !== [], 'the conversation to be closed');
        await($session->stop());

        $this->assertCount(1, $this->played);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testTheCallCanEndWhileOkayIsBeingSpoken(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => $this->quietTimers() !== [], 'the conversation to be quiet');

        // The voice client never says "Okay." was spoken when it is closed while speaking it.
        $this->playing = (new Deferred())->promise();
        $this->say($vc, '555', 'Stop, Claude.');
        $this->waitUntil(fn () => count($this->played) === 2, 'okay to be spoken');
        await($session->stop());

        $this->assertSame([], $this->timers->pending());
        $this->assertSame([], $this->loggedProblems());
    }

    public function testEveryConversationEndsWithTheCall(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->ask($vc, '666', 'Claude, what is the date?');
        $this->waitUntil(fn () => count($this->quietTimers()) === 2, 'both conversations to be quiet');

        await($session->stop());

        // No timer is left in the event loop to keep the bot, or a test, waiting.
        $this->assertSame([], $this->timers->pending());
    }

    public function testDoesNotStartAMinuteForAnAnswerThatTheCallCutOff(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        // The answer is still being spoken when the call ends.
        $this->playing = (new Deferred())->promise();
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to be spoken');

        await($session->stop());

        $this->assertSame([], $this->timers->pending());
    }

    public function testLogsNeverHoldWhatWasSaid(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->say($vc, '555', 'Stop, Claude.');
        $this->waitUntil(fn () => count($this->played) === 2, 'okay to be spoken');

        $opened = $this->logged('Conversation opened')[0];
        $closed = $this->logged('Conversation closed')[0];
        $this->assertSame(['guild', 'session', 'user'], array_keys($opened));
        $this->assertSame(['guild', 'session', 'user', 'reason'], array_keys($closed));
        $this->assertSame([self::GUILD_ID, $session->id], [$opened['guild'], $opened['session']]);
        $this->assertSame([self::GUILD_ID, $session->id], [$closed['guild'], $closed['session']]);
        $this->assertLogsNeverMention('what time', 'Stop, Claude', 'quarter past', 'Okay');
    }

    public function testTheLeavePhraseEndsTheCallTheWayStopDoes(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT_MEMORY' => $this->claudeSays('- Updated by Claude.')]);
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 1 && $this->waitingClaudes() !== [] && $this->pipers() !== [], 'the answer to be spoken');
        $processes = [...$this->waitingClaudes(), ...$this->pipers()];

        $this->say($vc, '555', 'Disconnect, Claude.');
        // Not await($session->stop()): a turn that waited for itself would hang there, and here it times out.
        $this->callEnds($session);

        // Gone from the server, and the voice client was closed, once: voiceClient() expects it.
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        // Okay is said to the end first, and the channel is told who ended the call before the summary comes.
        $this->assertSame([$this->played[0], "{$session->directory}/claude-3.ogg"], $this->played);
        $this->assertSame('Okay.', file_get_contents($this->played[1]));
        $this->assertSame([], $this->cutOff);
        $this->assertSame([
            '> **Alice:** Hey Claude, what time is it?' . "\n" . self::ANSWER,
            'Alice ended the call by voice.',
            self::ANSWER,
        ], $this->sent);
        $this->assertSame(self::ANSWER . "\n", file_get_contents("{$session->directory}/summary.md"));
        $this->assertCount(1, $this->memoryUpdates());
        $this->assertSame('- Updated by Claude.', $this->memory()->read('555'));
        // Claude Code and Piper ended with the call.
        $this->waitUntil(fn () => array_filter($processes, $this->isRunning(...)) === [], 'Claude Code and Piper to end');
        $this->assertSame([], $this->timers->pending());

        // The sentence is in the transcript, and Claude was never asked what to do about it.
        $this->assertStringEndsWith("] Alice: Disconnect, Claude.\n", $this->transcript($session));
        $this->assertCount(1, array_filter($this->claudeCalls(), fn (array $call) => str_contains($call['prompt'], 'is talking to you')));
        $this->assertSame([['user' => '555']], $this->contexts('Ended by the leave phrase'));
        $this->assertCount(1, $this->logged('Voice session stopped'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAnyoneInTheCallCanSayTheLeavePhraseWithoutHavingAConversation(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->hearsNoAnswer($vc, $session, '555', 'We should get going.');

        // Carol has no conversation open, and nothing opens one: it is no question either.
        $this->say($vc, '777', 'Disconnect Claude.');
        $this->callEnds($session);

        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame('Okay.', file_get_contents($this->played[0]));
        $this->assertSame('Carol ended the call by voice.', $this->sent[0]);
        $this->assertSame([], $this->logged('Conversation opened'));
        $this->assertSame([['user' => '777']], $this->contexts('Ended by the leave phrase'));
        // Only the summary and the memories were asked of Claude.
        $this->assertSame([], array_filter($this->claudeCalls(), fn (array $call) => str_contains($call['prompt'], 'is talking to you')));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testTheLeavePhraseFollowsTheServersWakeWord(): void
    {
        (new GuildSettings(new Logger('test')))->save(self::GUILD_ID, [...GuildSettings::DEFAULTS, 'wake_word' => 'computer'], '555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('disconnect computer', $session->leavePhrase);

        // The phrase of a server with another wake word is only a sentence here.
        $this->hearsNoAnswer($vc, $session, '555', 'Disconnect Claude.');
        $this->assertSame($session, VoiceSession::forGuild(self::GUILD_ID));

        $this->say($vc, '555', 'Okay, disconnect computer.');
        $this->callEnds($session);

        $this->assertSame([['user' => '555']], $this->contexts('Ended by the leave phrase'));
    }

    public function testTheLeavePhraseIsHeardForEverySpellingOfTheWakeWord(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => 'claude, cloud, claud']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('disconnect claude, disconnect cloud, disconnect claud', $session->leavePhrase);

        // Whisper writes "Claude" as "Claud" when it mishears it, in the leave phrase too.
        $this->say($vc, '555', 'Disconnect, claud.');
        $this->callEnds($session);

        $this->assertSame([['user' => '555']], $this->contexts('Ended by the leave phrase'));
    }

    public function testTheLeavePhraseInTheEnvReplacesTheDefaultForEveryServer(): void
    {
        $this->setEnv(['VOICE_LEAVE_PHRASE' => ' hang up claude ,, hang up on claude ']);
        // This server has a wake word of its own, and the same leave phrase as every other.
        (new GuildSettings(new Logger('test')))->save(self::GUILD_ID, [...GuildSettings::DEFAULTS, 'wake_word' => 'computer'], '555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('hang up claude, hang up on claude', $session->leavePhrase);

        // The default no longer ends the call: it is a question to Claude.
        $this->ask($vc, '555', 'Disconnect computer.');
        $this->assertSame($session, VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame([], $this->logged('Ended by the leave phrase'));

        $this->say($vc, '555', 'Hang up on, Claude.');
        $this->callEnds($session);

        $this->assertSame([['user' => '555']], $this->contexts('Ended by the leave phrase'));
    }

    public function testTheLeavePhraseInTheEnvWorksInAServerWithoutAWakeWord(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => '', 'VOICE_LEAVE_PHRASE' => 'hang up']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('hang up', $session->leavePhrase);

        // Everything is answered there, but this is not.
        $this->ask($vc, '555', 'What time is it?');
        $this->say($vc, '555', 'Hang up.');
        $this->callEnds($session);

        $this->assertSame([['user' => '555']], $this->contexts('Ended by the leave phrase'));
        $this->assertCount(1, array_filter($this->claudeCalls(), fn (array $call) => str_contains($call['prompt'], 'is talking to you')), 'Only the question was answered.');
    }

    public function testAServerWithoutAWakeWordHasNoLeavePhraseByDefault(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => '']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('', $session->leavePhrase);

        // Answered like any other sentence, and the call goes on.
        $this->ask($vc, '555', 'Disconnect Claude.');

        $this->assertSame($session, VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame([], $this->logged('Ended by the leave phrase'));
    }

    public function testOnlyTheWholePhraseEndsTheCall(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // "Disconnect" alone is a word people say in a call.
        $this->hearsNoAnswer($vc, $session, '666', 'I got disconnect, I mean disconnected.');
        $this->hearsNoAnswer($vc, $session, '666', 'Disconnect.');
        // These hold the wake word, so they are questions, answered like any other.
        $this->ask($vc, '555', 'Disconnected Claude.');
        $this->ask($vc, '555', 'Claude, I got disconnected.');
        $this->ask($vc, '555', 'Claude, disconnect.');
        $this->ask($vc, '555', 'Disconnect from Claude.');

        $this->runFor(0.5);
        $this->assertSame($session, VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame([], $this->logged('Ended by the leave phrase'));
        $this->assertCount(4, $this->played, 'Only the four answers were said.');
    }

    public function testASentenceThatHoldsTheLeavePhraseEndsTheCallWhateverElseItSays(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // The README says so: "don't stop Claude" closes a conversation in the same way.
        $this->say($vc, '555', 'Please, don\'t disconnect Claude!');
        $this->callEnds($session);

        $this->assertSame([['user' => '555']], $this->contexts('Ended by the leave phrase'));
    }

    public function testTheLeavePhraseIsCheckedBeforeTheStopPhrase(): void
    {
        // A stop phrase someone set that holds the leave phrase: it would only close the conversation.
        $this->setEnv(['VOICE_STOP_PHRASE' => 'disconnect claude, stop claude']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->say($vc, '555', 'Disconnect Claude.');
        $this->callEnds($session);

        $this->assertSame([['user' => '555']], $this->contexts('Ended by the leave phrase'));
        $this->assertSame([], $this->logged('Conversation closed'), 'It was not taken for the stop phrase.');
    }

    public function testTheLeavePhraseIsNotHeardInACallThatEnded(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Disconnect Claude.']);
        // Said before the call stopped, and transcribed after.
        $this->speakAndWait($vc, '555');
        $ended = $session->stop();
        $this->transcribe();
        await($ended);

        // It is in the transcript, like everything said before the call was over, and nobody is told anything.
        $this->assertStringEndsWith("] Alice: Disconnect Claude.\n", $this->transcript($session));
        $this->assertSame([], $this->logged('Ended by the leave phrase'));
        $this->assertSame([], $this->played);
        $this->assertNotContains('Alice ended the call by voice.', $this->sent);
    }

    public function testALeavePhraseSaidOverAnAnswerToSomeoneElseWaitsForItsTurn(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '666', 'Hey Claude, are you there?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to Bob to be spoken');
        $speaking = new Deferred();
        $this->playing = $speaking->promise();
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 2, 'the answer to Alice to be spoken');

        // Bob ends the call while the bot is answering Alice.
        $this->say($vc, '666', 'Disconnect Claude.');
        $this->waitUntil(fn () => count($this->logged('Utterance ended')) === 3, 'Bob to finish speaking');
        $this->runFor(0.5);

        // The answer to Alice is spoken to the end first, and the call goes on until then.
        $this->assertSame([], $this->cutOff);
        $this->assertSame($session, VoiceSession::forGuild(self::GUILD_ID));
        $this->assertCount(2, $this->played);

        $speaking->resolve(null);
        $this->callEnds($session);

        $this->assertSame([], $this->cutOff, 'Nothing was cut off.');
        $this->assertCount(3, $this->played);
        $this->assertSame('Okay.', file_get_contents($this->played[2]));
        $this->assertSame([['user' => '666']], $this->contexts('Ended by the leave phrase'));
        $this->assertSame('Bob ended the call by voice.', $this->sent[2]);
    }

    public function testALeavePhraseSaidOverTheirOwnAnswerStopsTheBotAndEndsTheCall(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        // The sentence the bot is speaking is a long one.
        $this->playing = (new Deferred())->promise();
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to be spoken');

        // As she is the one being answered, talking over it stops the bot, and then okay is said, and the call ends.
        $this->playing = null;
        $this->say($vc, '555', 'Disconnect Claude.');
        $this->callEnds($session);

        $this->assertSame([$this->played[0]], $this->cutOff);
        $this->assertCount(1, $this->logged('Interrupted'));
        $this->assertCount(2, $this->played);
        $this->assertSame('Okay.', file_get_contents($this->played[1]));
        $this->assertSame([['user' => '555']], $this->contexts('Ended by the leave phrase'));
    }

    public function testTheCallIsNotStoppedUntilOkayIsSpokenToTheEnd(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        // The voice client is still playing "Okay." for as long as the test says: stopping the call would cut it off.
        $okay = new Deferred();
        $this->playing = $okay->promise();

        $this->say($vc, '555', 'Disconnect Claude.');
        $this->waitUntil(fn () => count($this->played) === 1, 'okay to be spoken');
        $this->runFor(0.5);

        $this->assertSame($session, VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame([], $this->cutOff);
        $this->assertSame([], $this->logged('Ended by the leave phrase'));
        $this->assertSame([], $this->sent);

        $okay->resolve(null);
        $this->callEnds($session);

        $this->assertSame([], $this->cutOff, '"Okay." was spoken to the end.');
        $this->assertSame(['Alice ended the call by voice.', self::ANSWER], $this->sent);
    }

    public function testLeavesAllTheSameWhenOkayCannotBeSpoken(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);
        $this->setProcessEnv(['FAKE_PIPER_FAILS_ON' => 'Okay.']);

        $this->say($vc, '555', 'Disconnect Claude.');
        $this->callEnds($session);

        // A warning in the log, and the call is over, summarized, and the channel told.
        $this->assertSame([], $this->played);
        $this->assertCount(1, $this->loggedProblems());
        $this->assertStringStartsWith('Could not say okay: ', $this->loggedProblems()[0]);
        $this->assertSame('555', $this->logged($this->loggedProblems()[0])[0]['user']);
        $this->assertSame(['Alice ended the call by voice.', self::ANSWER], $this->sent);
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
    }

    public function testTheLeavePhraseOfSomeoneWhoOptedOutWhileItWaitedIsNeverHeard(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Disconnect Claude.']);
        $this->speakAndWait($vc, '555');
        VoiceSession::optOut('555');
        $this->transcribe();
        $this->runFor(0.5);

        // What they said while they were recorded is dropped with them: it was never heard.
        $this->assertSame($session, VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame([], $this->logged('Ended by the leave phrase'));
    }

    public function testDoesNotEndTheCallAgainWhenItEndedWhileOkayWasBeingMade(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->setProcessEnv(['FAKE_PIPER_DELAY' => '0.5']);
        $this->say($vc, '555', 'Disconnect Claude.');
        // Transcribed, so it has been heard and okay is being made, which takes half a second.
        $this->waitUntil(fn () => str_contains($this->transcript($session), 'Alice: Disconnect Claude.'), 'it to be transcribed');
        // /stop, or someone disconnecting the bot, ends it first.
        await($session->stop());
        $this->runFor(0.5);

        $this->assertSame([], $this->logged('Ended by the leave phrase'));
        $this->assertSame([self::ANSWER], $this->sent, 'Only the summary was posted.');
        $this->assertCount(1, $this->logged('Voice session stopped'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testTheLogOfTheLeavePhraseHoldsNoIdsButTheirsAndNeverWhatWasSaid(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->say($vc, '555', 'Disconnect Claude.');
        $this->callEnds($session);

        $left = $this->logged('Ended by the leave phrase')[0];
        $this->assertSame(['guild', 'session', 'user'], array_keys($left));
        $this->assertSame([self::GUILD_ID, $session->id, '555'], array_values($left));
        // Not even the name that the text channel was told.
        $this->assertLogsNeverMention('Disconnect Claude', 'ended the call', 'Alice', 'Okay', 'quarter past');
    }

    /**
     * The call is over: stopped, and summarized, its memories updated and Piper ended.
     */
    private function callEnds(VoiceSession $session): void
    {
        $this->waitUntil(fn () => ! in_array($session, VoiceSession::unfinished(), true), 'the call to be over');
    }

    /**
     * Someone says something, without waiting for what happens.
     */
    private function say(VoiceClient $vc, string $userId, string $text): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => $text]);
        $this->speak($vc, ssrc: (int) $userId, userId: $userId, seconds: 1.0);
    }

    /**
     * Someone says something that the bot hears, and doesn't answer.
     */
    private function hearsNoAnswer(VoiceClient $vc, VoiceSession $session, string $userId, string $text): void
    {
        $sent = $this->sent;
        $played = $this->played;
        $asked = count($this->claudeCalls());

        $this->say($vc, $userId, $text);
        $this->waitUntil(fn () => str_contains($this->transcript($session), '] ' . self::MEMBERS[$userId] . ": {$text}\n"), 'it to be transcribed');
        $this->runFor(0.5);

        $this->assertSame($sent, $this->sent, 'Nothing was posted.');
        $this->assertSame($played, $this->played, 'Nothing was said.');
        $this->assertSame($asked, count($this->claudeCalls()), 'Claude was not asked.');
    }

    /**
     * @return list<float> The seconds of the timers that close a conversation once its person is quiet.
     */
    private function quietTimers(): array
    {
        return array_values(array_filter($this->timers->pending(), fn (float $seconds) => $seconds === 60.0));
    }

    /**
     * @return list<array<string, mixed>> What each time the message was logged says, beyond the guild and session.
     */
    private function contexts(string $message): array
    {
        return array_map(fn (array $context) => array_slice($context, 2), $this->logged($message));
    }
}
