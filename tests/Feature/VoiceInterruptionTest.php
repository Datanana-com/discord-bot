<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Voice\VoiceSession;
use React\Promise\Deferred;

/**
 * The bot stops speaking when the person it is answering talks over it.
 */
final class VoiceInterruptionTest extends VoiceTestCase
{
    private const string QUESTION = '> **Alice:** Hey Claude, what time is it?';

    private const string ANSWER = 'It is a quarter past four. Time for a cup of tea. The kettle is already on.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past four. ', 'Time for a cup of tea. ', 'The kettle is already on.')]);
    }

    public function testStopsSpeakingWhenThePersonItAnswersTalksOverIt(): void
    {
        // The sentence being spoken is a long one, and Claude is still writing the rest of its answer. It took
        // a second before its first word.
        $this->playing = (new Deferred())->promise();
        $this->setProcessEnv(['FAKE_CLAUDE_PAUSE' => '10', 'FAKE_CLAUDE_DELAY' => '1']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');
        $speakingSince = microtime(true);
        $this->runFor(0.3);

        // Alice laughs over it, which whisper makes no words of. Less than half a second of it could be a cough.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => '(laughs)']);
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 0.4);
        $this->assertSame([], $this->cutOff);
        $this->assertSame([], $this->logged('Interrupted'));
        $this->runFor(0.1);

        // With half a second, she is heard: the sentence is cut off, at once.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 0.1);
        $spokenFor = (microtime(true) - $speakingSince) * 1000;
        $this->assertSame(["{$session->directory}/claude-2.ogg"], $this->cutOff);

        // She goes on, which interrupts nothing more: the bot is silent, and Claude is still writing.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 0.6);
        $this->assertSame(["{$session->directory}/claude-2.ogg"], $this->cutOff);

        // It is logged once, with who interrupted, in which call, how long the bot had been speaking, and how
        // long after she started talking over it the bot was silent.
        $interrupted = $this->logged('Interrupted');
        $this->assertCount(1, $interrupted);
        $this->assertSame(['guild', 'session', 'user', 'ms', 'after_ms'], array_keys($interrupted[0]));
        $this->assertSame([self::GUILD_ID, $session->id, '555'], array_slice(array_values($interrupted[0]), 0, 3));
        $this->assertIsInt($interrupted[0]['ms']);
        // Since its first sentence started, a moment before the test noticed: not since the question, a second
        // and more ago, nor since what Alice just said.
        $this->assertGreaterThanOrEqual(300, $interrupted[0]['ms']);
        $this->assertLessThan($spokenFor + 500, $interrupted[0]['ms']);
        // From the first of her laugh, not from the last bit of it: the test took its time between the two.
        $this->assertIsInt($interrupted[0]['after_ms']);
        $this->assertGreaterThanOrEqual(100, $interrupted[0]['after_ms']);
        $this->assertLessThan($spokenFor, $interrupted[0]['after_ms'], 'Not since the bot started speaking.');

        // Claude writes the rest. The answer is posted whole, and is in the transcript, like one cut off by /stop.
        touch($this->claudeResume);
        $this->waitUntil(fn () => count($this->logged('Transcribed')) === 2 && $this->sent !== [], 'what Alice did over the answer to be transcribed, and the answer to be posted');

        $this->assertSame([self::QUESTION . "\n" . self::ANSWER], $this->sent);
        $this->assertSame(1, $this->usage()['answers']);
        $this->assertStringEndsWith('] Claude: ' . self::ANSWER . "\n", $this->transcript($session));

        // The rest of it is neither spoken nor synthesized.
        $this->assertCount(1, $this->played);
        $this->assertSame(["{$session->directory}/claude-2.ogg"], glob("{$session->directory}/claude-*"));
        $this->assertSame([], $this->loggedProblems());
        $this->assertLogsNeverMention('laughs', 'quarter past four');
    }

    public function testAnswersWhatWasSaidOverAnAnswerWhenItWasSaidToClaude(): void
    {
        $this->playing = (new Deferred())->promise();
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => is_file("{$session->directory}/claude-4.ogg"), 'the last sentence to be synthesized');

        // Alice interrupts with another question. The two sentences that were ready are not spoken.
        $this->playing = null;
        $this->setProcessEnv([
            'FAKE_WHISPER_OUTPUT' => 'Claude, and in Lisbon?',
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past five there.'),
        ]);
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 0.6);
        $this->waitUntil(fn () => count($this->played) === 2, 'the next answer to be spoken');

        // The bot goes on to her new question, and speaks its answer to the end.
        $this->assertSame(["{$session->directory}/claude-2.ogg"], $this->cutOff);
        $this->assertSame(["{$session->directory}/claude-2.ogg", "{$session->directory}/claude-5.ogg"], $this->played);
        $this->assertSame('It is a quarter past five there.', file_get_contents($this->played[1]));
        $this->assertSame(
            [self::QUESTION . "\n" . self::ANSWER, "> **Alice:** Claude, and in Lisbon?\nIt is a quarter past five there."],
            $this->sent,
        );
        $this->assertCount(2, $this->logged('Started speaking'));
        $this->assertCount(1, $this->logged('Interrupted'));
        $this->assertSame([], $this->loggedProblems());

        // What she said over the answer is handled like anything else she says, after the answer it interrupted.
        $this->assertMatchesRegularExpression(
            '/\] Alice: Hey Claude, what time is it\?\n\[[\d:]+\] Claude: ' . preg_quote(self::ANSWER, '/')
                . '\n\[[\d:]+\] Alice: Claude, and in Lisbon\?\n\[[\d:]+\] Claude: It is a quarter past five there\.\n$/',
            $this->transcript($session),
        );
    }

    public function testOnlyThePersonBeingAnsweredCanInterrupt(): void
    {
        $finished = new Deferred();
        $this->playing = $finished->promise();
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');

        // Bob and Carol talk to each other during the answer to Alice.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Did you see the game?']);
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $this->speak($vc, ssrc: 3, userId: '777', seconds: 1.0);

        $this->assertSame([], $this->cutOff);

        $finished->resolve(null);
        $this->waitUntil(fn () => count($this->played) === 3, 'every sentence to be spoken');

        $this->assertSame([], $this->logged('Interrupted'));
        $this->assertSame([], $this->cutOff);
    }

    public function testShortNoisesDoNotAddUpToAnInterruption(): void
    {
        $finished = new Deferred();
        $this->playing = $finished->promise();
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');

        // Alice coughs, and a moment later laughs: each is too short to be her talking.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 0.3);
        $this->runFor(0.8);
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 0.3);

        $this->assertSame([], $this->cutOff);

        $finished->resolve(null);
        $this->waitUntil(fn () => count($this->played) === 3, 'every sentence to be spoken');

        $this->assertSame([], $this->logged('Interrupted'));
        $this->assertCount(1, $this->logged('Utterance ended'), 'Only her question: neither noise was long enough to be transcribed.');
    }

    public function testWhatCountsAsOneThingSaidAlsoInterruptsAsOne(): void
    {
        // In a server where people pause for long in the middle of a sentence.
        $this->setEnv(['VOICE_PAUSE_SECONDS' => '3']);
        $this->playing = (new Deferred())->promise();
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken', timeout: 15.0);

        // Alice starts on something, stops for a second, and goes on: to the bot that is one thing she says, which it
        // will transcribe as one, so it also counts as one for talking over the answer.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 0.3);
        $this->runFor(1.0);
        $this->assertSame([], $this->cutOff);
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 0.3);

        $this->assertSame(["{$session->directory}/claude-2.ogg"], $this->cutOff);
        $this->assertCount(1, $this->logged('Interrupted'));
    }

    public function testWhatWasSaidBeforeTheBotSpokeDoesNotCountAsTalkingOverIt(): void
    {
        $this->playing = (new Deferred())->promise();
        // Claude takes a moment before its first word.
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0.5']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->logged('Transcribed') !== [], 'the question to be transcribed');

        // Alice adds something while she waits for the answer, and is still at it when the bot starts speaking.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 0.4);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 0.2);

        $this->assertSame([], $this->cutOff, 'Only a fifth of a second of it was said over the bot.');

        // She keeps talking: now it is half a second.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 0.3);

        $this->assertSame(["{$session->directory}/claude-2.ogg"], $this->cutOff);
    }

    public function testStopsAnAnswerBetweenTwoSentences(): void
    {
        // Each sentence is short, and Piper takes a while over the next one.
        $this->setProcessEnv(['FAKE_PIPER_DELAY' => '0.5']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');

        // The bot has said the first sentence and Piper is working on the second, when Alice starts humming.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => '(humming)']);
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 0.5);

        $this->assertCount(1, $this->logged('Interrupted'));
        $this->assertSame([], $this->cutOff, 'Nothing was playing.');

        $this->waitUntil(fn () => count($this->logged('Transcribed')) === 2, 'what Alice said to be transcribed');

        // The second sentence was already with Piper, which finished it. It isn't spoken, and its file isn't kept.
        // The third never gets to Piper.
        $this->assertCount(1, $this->played);
        $this->assertSame(['It is a quarter past four.', 'Time for a cup of tea.'], $this->givenToPiper());
        $this->waitUntil(fn () => glob("{$session->directory}/claude-*") === ["{$session->directory}/claude-2.ogg"], 'the file of the sentence nobody heard to be deleted');
        $this->assertSame([self::QUESTION . "\n" . self::ANSWER], $this->sent);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testSomeoneWhoOptedOutDoesNotInterrupt(): void
    {
        $finished = new Deferred();
        $this->playing = $finished->promise();
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => is_file("{$session->directory}/claude-4.ogg"), 'the last sentence to be synthesized');

        // Alice opts out during the answer: the bot no longer listens to her, and the rest of it isn't spoken anyway.
        VoiceSession::optOut('555');
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);

        $this->assertSame([], $this->cutOff);
        $this->assertSame([], $this->logged('Interrupted'));

        $finished->resolve(null);
        $this->runFor(0.3);
        $this->assertCount(1, $this->played);
    }
}
