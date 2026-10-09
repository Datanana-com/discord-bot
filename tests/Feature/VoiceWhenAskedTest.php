<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Settings\GuildSettings;
use App\Voice\VoiceSession;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use React\Promise\Deferred;

/**
 * The bot answers a sentence that names it, and nothing else: people in a call talk to each other, and
 * what someone says is never answered because of what they said before. The one exception is a sentence
 * that only calls the bot ("Hey Claude."), which makes what that person says next their question.
 *
 * Alice, Bob and Carol are 555, 666 and 777. Whatever whisper hears in a test is what the last one
 * to speak said, so nothing here lets two people talk before their first sentence is transcribed.
 */
final class VoiceWhenAskedTest extends VoiceTestCase
{
    private const string ANSWER = 'It is a quarter past four.';

    public function testAnswersASentenceThatNamesTheBotAndNothingSaidAfterIt(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hi, Claude. How are you doing?');
        // A follow-up needs the name again, also from someone alone with the bot.
        $this->hearsNoAnswer($vc, $session, '555', 'Nothing much. What about you?');
        $this->ask($vc, '555', 'Claude, tell me a joke.');
        $this->hearsNoAnswer($vc, $session, '555', 'That was a good one.');

        $this->assertSame([
            '> **Alice:** Hi, Claude. How are you doing?' . "\n" . self::ANSWER,
            '> **Alice:** Claude, tell me a joke.' . "\n" . self::ANSWER,
        ], $this->sent);
        $this->assertSame(
            [['user' => '555', 'reason' => 'Claude was not addressed'], ['user' => '555', 'reason' => 'Claude was not addressed']],
            $this->contexts('Not answering'),
        );
        // Nothing is opened, and nothing is left to close: the log lines of conversations are gone.
        $this->assertSame([], $this->logged('Conversation opened'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testPeopleTalkingToEachOtherAreNotAnsweredAfterOneOfThemAskedOnce(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->inCall('555', '666');

        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        // What they then say to each other is nothing the bot was asked.
        $this->hearsNoAnswer($vc, $session, '666', 'Then we have an hour left.');
        $this->hearsNoAnswer($vc, $session, '555', 'Enough for the report, I think.');
        $this->hearsNoAnswer($vc, $session, '666', 'What about the budget?');
        $this->hearsNoAnswer($vc, $session, '555', 'Not today.');

        $this->assertSame(['> **Alice:** Hey Claude, what time is it?' . "\n" . self::ANSWER], $this->sent);
        $this->assertCount(1, $this->claudeCalls(), 'Claude was asked once.');
        $this->assertCount(1, $this->played);
        // Everything said is in the transcript, answered or not.
        $this->assertSame(
            "Alice: Hey Claude, what time is it?\nClaude: " . self::ANSWER . "\nBob: Then we have an hour left.\nAlice: Enough for the report, I think.\nBob: What about the budget?\nAlice: Not today.\n",
            $this->untimed($this->transcript($session)),
        );
    }

    public function testTheStopPhraseIsNeverSentToClaudeAndNothingIsSaid(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to be spoken');

        // Whisper punctuates it, and it contains the wake word, which doesn't make it a question.
        $this->hearsNoAnswer($vc, $session, '555', 'Stop, Claude.');

        // No "Okay." any more: there is nothing it would close.
        $this->assertCount(1, $this->played);
        $this->assertSame([['user' => '555', 'reason' => 'the stop phrase']], $this->contexts('Not answering'));
        $this->assertStringEndsWith("] Alice: Stop, Claude.\n", $this->transcript($session));
        $this->assertSame(1, $this->usage()['answers']);

        // It is the name and one other word, and still not what makes the next sentence a question.
        $this->hearsNoAnswer($vc, $session, '555', 'Thanks, bye.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testASentenceThatHoldsTheStopPhraseIsNotAnsweredWhateverElseItSays(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->hearsNoAnswer($vc, $session, '777', 'Okay, stop Claude, we heard enough of that for today.');

        $this->assertSame([['user' => '777', 'reason' => 'the stop phrase']], $this->contexts('Not answering'));
        $this->ask($vc, '777', 'Claude, what time is it?');
    }

    public function testTheDefaultStopPhraseFollowsTheServersWakeWord(): void
    {
        (new GuildSettings(new Logger('test')))->save(self::GUILD_ID, [...GuildSettings::DEFAULTS, 'wake_word' => 'computer'], '555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('stop computer', $session->stopPhrase);

        // The phrase of a server with another wake word is only a sentence here.
        $this->ask($vc, '555', 'Hey computer, stop Claude, please.');
        $this->hearsNoAnswer($vc, $session, '555', 'Okay, stop computer, that is enough for now.');

        $this->assertSame([['user' => '555', 'reason' => 'the stop phrase']], $this->contexts('Not answering'));
    }

    public function testTheDefaultStopPhraseIsHeardForEverySpellingOfTheWakeWord(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => 'claude, cloud, claud']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('stop claude, stop cloud, stop claud', $session->stopPhrase);

        // Whisper writes "Claude" as "Cloud" and "Claud" when it mishears it, in the stop phrase too.
        $this->ask($vc, '555', 'Hey Cloud, what time is it?');
        $this->hearsNoAnswer($vc, $session, '555', 'Stop, cloud, I do not want to hear that.');
        $this->hearsNoAnswer($vc, $session, '555', 'Stop Claud, I do not want to hear that.');

        $this->assertSame(['the stop phrase', 'the stop phrase'], array_column($this->logged('Not answering'), 'reason'));
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
        $this->hearsNoAnswer($vc, $session, '555', 'Stop, claud, that is enough for today.');

        // "Cloud" is not a spelling of the default: people talk about the cloud.
        $this->hearsNoAnswer($vc, $session, '666', 'The cloud is down.');
        $this->assertSame(['the stop phrase', 'Claude was not addressed'], array_column($this->logged('Not answering'), 'reason'));
    }

    public function testTheStopPhraseInTheEnvReplacesTheDefaultForEveryServer(): void
    {
        $this->setEnv(['VOICE_STOP_PHRASE' => ' para computer ']);
        // This server has a wake word of its own, and the same stop phrase as every other.
        (new GuildSettings(new Logger('test')))->save(self::GUILD_ID, [...GuildSettings::DEFAULTS, 'wake_word' => 'computer'], '555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('para computer', $session->stopPhrase);

        // The default is a question like any other now.
        $this->ask($vc, '555', 'Stop computer, and tell me the time.');
        $this->hearsNoAnswer($vc, $session, '555', 'Para, computer, and tell me the time.');

        $this->assertSame([['user' => '555', 'reason' => 'the stop phrase']], $this->contexts('Not answering'));
    }

    public function testWithoutAWakeWordEverythingIsAnsweredAndThereIsNoStopPhrase(): void
    {
        // Everything is answered, as before: also the stop phrase someone set, and a sentence that is only a name.
        $this->setEnv(['VOICE_WAKE_WORD' => '', 'VOICE_STOP_PHRASE' => 'para claude']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('', $session->stopPhrase);

        $this->ask($vc, '555', 'Para Claude, what time is it?');
        $this->ask($vc, '555', 'And what is the date?');
        $this->ask($vc, '666', 'Hey Claude.');

        $this->assertSame([
            '> **Alice:** Para Claude, what time is it?' . "\n" . self::ANSWER,
            '> **Alice:** And what is the date?' . "\n" . self::ANSWER,
            '> **Bob:** Hey Claude.' . "\n" . self::ANSWER,
        ], $this->sent);
        $this->waitUntil(fn () => count($this->played) === 3, 'the answers to be spoken');
        $this->assertSame([], $this->logged('Not answering'));
    }

    public function testEverythingIsAnsweredWhenTheWakeWordHasNoSpellingLeft(): void
    {
        // Only commas: like an empty wake word.
        $this->setEnv(['VOICE_WAKE_WORD' => ' , ']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('', $session->stopPhrase);

        $this->ask($vc, '555', 'What time is it?');
        $this->ask($vc, '555', 'Hello.');

        $this->assertSame([], $this->logged('Not answering'));
    }

    public function testEverythingIsAnsweredWhenASpellingOfTheWakeWordHasNoWord(): void
    {
        // A spelling without a letter or a number matches every sentence, so no sentence only calls the bot.
        (new GuildSettings(new Logger('test')))->save(self::GUILD_ID, [...GuildSettings::DEFAULTS, 'wake_word' => 'claude, ...'], '555');
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude.');
        $this->ask($vc, '555', 'Hello.');

        $this->assertSame([], $this->logged('Not answering'));
    }

    public function testAStopPhraseSaidOverAnAnswerToSomeoneElseStopsIt(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        // The sentence the bot is speaking is a long one: it never ends by itself here.
        $this->playing = (new Deferred())->promise();
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to Alice to be spoken');

        // Bob says it while the bot is answering Alice. Talking over it is not what stops it: it isn't his answer.
        $this->says($vc, '666', 'Stop, Claude.');
        $this->assertSame([], $this->cutOff);

        // The phrase is: anyone in the call can make the bot stop, and it doesn't wait for the answer to be over.
        $this->waitUntil(fn () => $this->cutOff !== [], 'the answer to be cut off');
        $this->assertSame([$this->played[0]], $this->cutOff);
        $this->assertSame([], $this->logged('Interrupted'));
        $this->assertSame([['user' => '666', 'reason' => 'the stop phrase']], $this->contexts('Not answering'));
        $stopped = $this->contexts('Stopped answering');
        $this->assertCount(1, $stopped);
        $this->assertSame(['user', 'by', 'reason', 'ms', 'spoken'], array_keys($stopped[0]));
        $this->assertSame(['user' => '555', 'by' => '666', 'reason' => 'the stop phrase'], array_slice($stopped[0], 0, 3));
        $this->assertIsInt($stopped[0]['ms']);
        $this->assertTrue($stopped[0]['spoken']);

        $this->runFor(0.3);
        $this->assertCount(1, $this->played, 'Nothing is said about it.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAStopPhraseSaidOverTheirOwnAnswerCutsItLikeAnythingTheySay(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        // The sentence the bot is speaking is a long one.
        $this->playing = (new Deferred())->promise();
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to be spoken');

        // Alice doesn't wait for it to end. As she is the one being answered, talking over it stops the bot.
        $this->playing = null;
        $this->says($vc, '555', 'Stop, Claude.');

        $this->assertSame([$this->played[0]], $this->cutOff);
        $this->assertCount(1, $this->logged('Interrupted'));

        // Her sentence has its turn as soon as she has said it. It is not answered, and nothing is said.
        $this->waitUntil(fn () => $this->logged('Not answering') !== [], 'the stop phrase to have its turn');
        $this->runFor(0.3);
        $this->assertSame([['user' => '555', 'reason' => 'the stop phrase']], $this->contexts('Not answering'));
        $this->assertCount(1, $this->played);
        $this->assertCount(1, $this->sent, 'Nothing else was answered.');
    }

    public function testWhatSomeoneSaysOverTheirAnswerIsOnlyAnsweredWhenItNamesTheBot(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->playing = (new Deferred())->promise();
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to be spoken');

        // She turns to someone else in the call while the bot is still speaking: it stops, and has nothing to add.
        $this->playing = null;
        $this->says($vc, '555', 'Never mind, did you see the game?');
        $this->waitUntil(fn () => $this->logged('Not answering') !== [], 'what she said to have its turn');
        $this->runFor(0.3);

        $this->assertSame([$this->played[0]], $this->cutOff);
        $this->assertSame([['user' => '555', 'reason' => 'Claude was not addressed']], $this->contexts('Not answering'));
        $this->assertCount(1, $this->claudeCalls());

        // With its name, what she says over the next answer is a question again.
        $this->playing = (new Deferred())->promise();
        $this->ask($vc, '555', 'Claude, what is the date?');
        $this->waitUntil(fn () => count($this->played) === 2, 'the second answer to be spoken');
        $this->playing = null;
        $this->ask($vc, '555', 'No, Claude, I meant the day of the week.');

        $this->assertSame([$this->played[0], $this->played[1]], $this->cutOff);
        $this->assertSame('> **Alice:** No, Claude, I meant the day of the week.' . "\n" . self::ANSWER, $this->sent[2]);
    }

    public function testASentenceThatOnlyCallsTheBotMakesWhatTheySayNextTheQuestion(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // "Hey Claude." and then, after a pause, the question: the pause made it two sentences.
        $this->hearsNoAnswer($vc, $session, '555', 'Hey Claude.');
        $this->assertSame([['user' => '555', 'reason' => 'only the wake word']], $this->contexts('Not answering'));

        $this->ask($vc, '555', 'What time is it?');

        // The question is what is answered, and what the answer is posted under.
        $this->assertSame(['> **Alice:** What time is it?' . "\n" . self::ANSWER], $this->sent);
        $this->assertSame(
            "Transcript of the voice call so far:\n\nAlice: Hey Claude.\nAlice: What time is it?\n\n" . $this->asking('Alice', 'What time is it?'),
            $this->untimed($this->claudeCalls()[0]['prompt']),
        );

        // That one sentence, and no more: the next needs the name.
        $this->hearsNoAnswer($vc, $session, '555', 'And the date?');
        $this->assertSame([], $this->loggedProblems());
    }

    /**
     * @param list<string> $calling Sentences that only call the bot.
     * @param list<string> $asking Sentences that are questions of their own.
     */
    #[DataProvider('sentencesWithTheWakeWord')]
    public function testOnlyTheWakeWordAndAtMostOneOtherWordCallTheBot(string $wakeWord, array $calling, array $asking): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => $wakeWord]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        foreach ($calling as $sentence) {
            $this->hearsNoAnswer($vc, $session, '555', $sentence);
        }

        $this->assertSame(array_fill(0, count($calling), 'only the wake word'), array_column($this->logged('Not answering'), 'reason'));

        foreach ($asking as $sentence) {
            $this->ask($vc, '555', $sentence);
        }

        $this->assertCount(count($asking), $this->claudeCalls());
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function sentencesWithTheWakeWord(): iterable
    {
        yield 'the name alone, or with one word' => ['claude', ['Claude.', 'Claude?', 'Hey, Claude!', 'Claude, yes?'], ['Okay, hey Claude.', 'Claude, are you?']];
        yield 'every spelling is the name' => ['claude, claud', ['Hey Claud.', 'Claude, Claud, Claude.', 'You, Claud.'], ['Hey you, Claud.']];
        yield 'a name of two words is one name' => ['okay computer', ['Okay, computer.', 'Hey, okay computer.'], ['Hey you, okay computer.']];
        yield 'a word with an apostrophe is one word' => ['claude', ["Claude, what's?", 'Claude, c’est ?'], ["Claude, what's up?"]];
        yield 'a language written without spaces has a word in each character' => ['claude', ['Claude, 嗨。'], ['Claude, 你好吗？', 'Claude, 今日の天気はどうですか', 'Claude สวัสดี']];
        yield 'numbers and letters with accents are words' => ['claude', ['Claude, 2.', 'Claude, é?'], ['Claude, 2 3.', 'Claude, é isso.', 'Claude, não é.']];
    }

    public function testWhatSomeoneElseSaysNextIsNotTheQuestion(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->hearsNoAnswer($vc, $session, '555', 'Hey Claude.');
        // Bob didn't call the bot: Alice did.
        $this->hearsNoAnswer($vc, $session, '666', 'What time is it?');
        // And she still has her few seconds.
        $this->ask($vc, '555', 'What is the date?');

        $this->assertSame(['> **Alice:** What is the date?' . "\n" . self::ANSWER], $this->sent);
    }

    public function testCallingTheBotAgainStartsOver(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->hearsNoAnswer($vc, $session, '555', 'Claude.');
        $this->hearsNoAnswer($vc, $session, '555', 'Hey, Claude?');
        $this->ask($vc, '555', 'What time is it?');

        $this->assertSame(['> **Alice:** What time is it?' . "\n" . self::ANSWER], $this->sent);
    }

    public function testTheStopPhraseAfterCallingTheBotIsNotAnsweredAndUsesItUp(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->hearsNoAnswer($vc, $session, '555', 'Hey Claude.');
        $this->hearsNoAnswer($vc, $session, '555', 'Stop Claude, forget it.');
        $this->hearsNoAnswer($vc, $session, '555', 'What time is it?');

        $this->assertSame(['only the wake word', 'the stop phrase', 'Claude was not addressed'], array_column($this->logged('Not answering'), 'reason'));
    }

    public function testWhatTheySayLongAfterCallingTheBotIsNotTheQuestion(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->hearsNoAnswer($vc, $session, '555', 'Hey Claude.');
        // More than the few seconds someone has: by now they are talking to the others again.
        $this->runFor(5.5);
        $this->hearsNoAnswer($vc, $session, '555', 'What time is it?');

        $this->assertSame(['only the wake word', 'Claude was not addressed'], array_column($this->logged('Not answering'), 'reason'));
    }

    public function testAPauseOfAFewSecondsAfterCallingTheBotIsStillInTime(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->hearsNoAnswer($vc, $session, '555', 'Hey Claude.');
        $this->runFor(2.5);
        // Half a second of speech, which is the shortest that counts: it started well within the time.
        $answers = count($this->sent);
        $this->says($vc, '555', 'What time is it?', seconds: 0.5);
        $this->waitUntil(fn () => count($this->sent) > $answers, 'the answer');

        $this->assertSame(['> **Alice:** What time is it?' . "\n" . self::ANSWER], $this->sent);
    }

    public function testALongQuestionCountsFromWhenTheyStartedSayingIt(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->hearsNoAnswer($vc, $session, '555', 'Hey Claude.');
        $this->runFor(4.0);
        // Four seconds of speech that end more than five seconds after "Hey Claude." did, and started before that.
        $this->says($vc, '555', 'What time is it in the place where my sister lives?', seconds: 4.0);
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        $this->assertSame(['> **Alice:** What time is it in the place where my sister lives?' . "\n" . self::ANSWER], $this->sent);
    }

    public function testAQuestionThatWaitsForItsTurnCountsFromWhenItWasSaid(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->hearsNoAnswer($vc, $session, '555', 'Hey Claude.');
        // The bot is answering Bob, at length, when Alice says what she wanted: it waits its turn behind that answer.
        $speaking = new Deferred();
        $this->playing = $speaking->promise();
        $this->ask($vc, '666', 'Claude, tell us a long story.');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to Bob to be spoken');
        $this->says($vc, '555', 'What time is it?');
        $this->waitUntil(fn () => count($this->logged('Utterance ended')) === 3, 'Alice to finish speaking');
        $this->runFor(5.5);

        // Its turn comes long after the five seconds, but she said it within them.
        $this->playing = null;
        $speaking->resolve(null);
        $this->waitUntil(fn () => count($this->sent) === 2, 'the answer to Alice');

        $this->assertSame('> **Alice:** What time is it?' . "\n" . self::ANSWER, $this->sent[1]);
    }

    public function testOptingOutForgetsThatTheyCalledTheBot(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->hearsNoAnswer($vc, $session, '555', 'Hey Claude.');
        VoiceSession::optOut('555');
        VoiceSession::optIn('555');

        // What they say once they are back in is nobody's question, whatever they said before they opted out.
        $this->hearsNoAnswer($vc, $session, '555', 'What time is it?');
        $this->assertSame(['only the wake word', 'Claude was not addressed'], array_column($this->logged('Not answering'), 'reason'));
    }

    public function testTheLogsOfWhatIsNotAnsweredNeverHoldWhatWasSaid(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->hearsNoAnswer($vc, $session, '555', 'Hey Claude.');
        $this->ask($vc, '555', 'What time is it?');
        $this->hearsNoAnswer($vc, $session, '555', 'Stop, Claude.');
        $this->hearsNoAnswer($vc, $session, '555', 'Lunch then.');

        foreach ($this->logged('Not answering') as $context) {
            $this->assertSame(['guild', 'session', 'user', 'reason'], array_keys($context));
            $this->assertSame([self::GUILD_ID, $session->id, '555'], [$context['guild'], $context['session'], $context['user']]);
        }

        $this->assertLogsNeverMention('Hey Claude', 'what time', 'What time', 'Stop, Claude', 'Lunch', 'quarter past', 'Alice');
    }
}
