<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Voice\VoiceSession;
use LogicException;
use React\Promise\Deferred;
use RuntimeException;
use Throwable;

use function React\Async\await;

/**
 * What a call does when something in it fails: it says so, in the call and in its text channel, and goes on
 * when it can. Run against the fake Discord and programs of {@see VoiceTestCase}.
 */
final class VoiceFailureTest extends VoiceTestCase
{
    private const string SORRY = 'Sorry, something went wrong.';

    private const string NOT_LOGGED_IN = "Sorry, I couldn't get an answer from Claude. (Claude Code: Not logged in · Please run /login)";

    private const string NOT_HEARD = "Sorry, I couldn't make out what was said. The bot's logs say why.";

    /** When set, the bot's caches fail with this when they are asked who someone is. */
    public ?Throwable $noNames = null;

    public function testTellsTheCallAndTheChannelWhenWhatWasSaidCannotBeTranscribed(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_EXIT' => '1']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the call to be told');

        // Neither says why: what whisper failed with holds its path on the bot's machine. That is in the log.
        $this->assertSame([self::NOT_HEARD], $this->sent);
        $this->assertSame([self::SORRY], array_map(file_get_contents(...), $this->played));
        $this->assertSame('] Claude: ' . self::SORRY . "\n", substr($this->transcript($session), 9));

        $this->assertCount(1, $this->loggedProblems());
        $this->assertStringStartsWith('Voice reply failed: ', $this->loggedProblems()[0]);
        $this->assertStringEndsWith('fake-whisper exited with code 1', $this->loggedProblems()[0]);
        $this->assertSame(['555', 'whisper'], array_values(array_intersect_key($this->logged($this->loggedProblems()[0])[0], ['user' => 0, 'step' => 0])));
        $this->assertSame([1, 0], [$this->usage()['failures'], $this->usage()['answers']]);
        // The fake whisper printed it before it failed.
        $this->assertLogsNeverMention('what time is it');
    }

    public function testTheCallHearsOnceThatTheSameThingFailsAndTheChannelIsToldEveryTime(): void
    {
        // With a login that expired, every question fails.
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => json_encode(['type' => 'result', 'is_error' => true, 'result' => 'Not logged in · Please run /login']),
            'FAKE_CLAUDE_EXIT' => '1',
        ]);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->played) === 1, 'the call to be told');
        $this->ask($vc, '555', 'Claude, are you there?');
        $this->ask($vc, '666', 'Claude, can you hear me?');
        $this->runFor(0.3);

        $this->assertSame([self::NOT_LOGGED_IN, self::NOT_LOGGED_IN, self::NOT_LOGGED_IN], $this->sent);
        $this->assertSame([self::SORRY], array_map(file_get_contents(...), $this->played), 'Whoever asks next, in the same call.');
        $this->assertSame(3, $this->usage()['failures'], 'Each one is counted.');

        // Something else that fails is new to the call.
        $this->setProcessEnv(['FAKE_WHISPER_EXIT' => '1']);
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->played) === 2, 'the call to be told again');
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->sent) === 5, 'the channel to be told again');
        $this->runFor(0.3);

        $this->assertSame([self::SORRY, self::SORRY], array_map(file_get_contents(...), $this->played));
        $this->assertSame([self::NOT_HEARD, self::NOT_HEARD], array_slice($this->sent, 3));
        $this->assertSame(5, $this->usage()['failures']);
    }

    public function testGoesOnWhenItCannotSayThatSomethingFailed(): void
    {
        // Piper is what is broken, at least for this sentence.
        $this->setProcessEnv(['FAKE_WHISPER_EXIT' => '1', 'FAKE_PIPER_FAILS_ON' => 'Sorry']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->loggedProblems()) === 2, 'both failures to be logged');

        $this->assertStringStartsWith('Voice reply failed: ', $this->loggedProblems()[0]);
        $this->assertStringStartsWith('Could not say sorry: ', $this->loggedProblems()[1]);
        $this->assertStringEndsWith('fake-piper exited with code 1: The voice model could not be loaded.', $this->loggedProblems()[1]);
        $this->assertSame([self::NOT_HEARD], $this->sent, 'The channel was told.');
        $this->assertSame([], $this->played);
        $this->assertSame(1, $this->usage()['failures'], 'What was said failed once.');

        // The next thing said is answered: nothing waits for the sentence that couldn't be spoken.
        $this->setProcessEnv(['FAKE_WHISPER_EXIT' => '0', 'FAKE_PIPER_FAILS_ON' => '']);
        $this->ask($vc, '555', 'Claude, what time is it?');
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');

        $this->assertSame('It is a quarter past four.', file_get_contents($this->played[0]));
        $this->assertSame(1, $this->usage()['answers']);
    }

    public function testSaysNoMoreThanThatItFailedWhenWhatFailedIsNothingItExpects(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // A bug, or a library that fails: while what was said is handled, after it was transcribed.
        $this->speakAndWait($vc, '555');
        $this->noNames = new LogicException('Cannot read /home/bot/databases/names.sqlite');
        $this->transcribe();
        $this->waitUntil(fn () => $this->played !== [], 'the call to be told');
        $this->noNames = null;

        // Such an error can hold paths, and other things nobody in a server needs: it is only in the log.
        $this->assertSame(['Sorry, something went wrong with what was said. The bot\'s logs say why.'], $this->sent);
        $this->assertSame([self::SORRY], array_map(file_get_contents(...), $this->played));
        $this->assertSame(['Voice reply failed: Cannot read /home/bot/databases/names.sqlite'], $this->loggedProblems());
        $this->assertSame('other', $this->logged($this->loggedProblems()[0])[0]['step']);
        $this->assertSame(1, $this->usage()['failures']);
        $this->assertNotNull(VoiceSession::forGuild(self::GUILD_ID), 'The call goes on.');
        $this->assertStringNotContainsString('names.sqlite', $this->transcript($session));
    }

    public function testACallThatStoppedIsOnlyToldInItsChannel(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_EXIT' => '1']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        // What was said last is still transcribed once the bot has left, for the transcript.
        $this->speakAndWait($vc, '555');
        $stopped = $session->stop();
        $this->transcribe();
        await($stopped);

        $this->assertSame([self::NOT_HEARD], $this->sent);
        $this->assertSame([], $this->played, 'The bot is no longer in the call.');
        // Nor is the sentence made for nobody, by a Piper started for it, or added to a transcript that has nothing else.
        $this->assertCount(1, $this->loggedProblems());
        $this->assertSame('', $this->transcript($session));

        // A call that is over isn't told that the bot leaves it, as when the bot then ends over errors.
        $session->abandon();
        $this->assertSame([self::NOT_HEARD], $this->sent);
    }

    public function testTellsTheChannelWhenTheVoiceClientCannotPlayASentence(): void
    {
        // Piper made the sentence, and the voice library fails to play it.
        $this->playError = new RuntimeException('The voice connection is gone');
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->sent) === 2, 'the channel to be told');
        $this->runFor(0.2);

        // Like a sentence Piper can't make: the answer is posted, and that it couldn't be said. Nothing more is tried in the call.
        $this->assertStringEndsWith("It is a quarter past four.", $this->sent[0]);
        $this->assertSame("Sorry, I couldn't say that out loud. The bot's logs say why.", $this->sent[1]);
        $this->assertSame(['Voice reply failed: The voice connection is gone'], $this->loggedProblems());
        $this->assertSame('speech', $this->logged('Voice reply failed: The voice connection is gone')[0]['step']);
        $this->assertSame([1, 1], [$this->usage()['failures'], $this->usage()['answers']]);
    }

    public function testSaysNothingMoreWhenTheCallStoppedWhileItsChannelWasTold(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_EXIT' => '1']);
        // Discord takes a moment with the message.
        $told = new Deferred();
        $this->sending = $told->promise();
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->sent) === 1, 'the channel to be told');
        $stopped = $session->stop();
        $told->resolve(null);
        await($stopped);

        // Nobody is left to hear it: no sentence is made by a Piper started for it, or added to the transcript.
        $this->assertSame([], $this->played);
        $this->assertSame('', $this->transcript($session));
        $this->assertCount(1, $this->pipers());
        $this->assertCount(1, $this->loggedProblems());
    }

    public function testTellsTheCallOnlyOnceItHasStoppedSpeakingTheAnswerThatFailed(): void
    {
        // A call of two, with a memory of them together: the bot looks at who is in the channel while it answers.
        $this->memory()->save(['555', '666'], 'They are planning a trip.');
        $this->inCall('555', '666');
        $this->playSeconds = 0.6;
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeText('It is a quarter past four. ') . "\n" . self::claudeResult('It is a quarter past four.'),
            'FAKE_CLAUDE_PAUSE' => '10',
        ]);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');
        // While it is spoken, Claude finishes, and what the bot does with a finished answer fails.
        $this->voiceStates[] = $this->unreadable();
        touch($this->claudeResume);
        $this->waitUntil(fn () => count($this->played) === 2, 'the call to be told');
        $this->inCall('555', '666');

        // After the sentence, not over it: the voice client plays one file at a time, and refuses another.
        $this->assertSame(['It is a quarter past four.', self::SORRY], array_map(file_get_contents(...), $this->played));
        $this->assertSame(['Voice reply failed: The voice states cannot be read'], $this->loggedProblems());
        $this->assertSame(['Sorry, something went wrong with what was said. The bot\'s logs say why.'], $this->sent);
    }

    public function testDoesNotKeepASorryNobodyHeardWhenTheCallEndsWhileItIsMade(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_EXIT' => '1', 'FAKE_PIPER_DELAY' => '0.5']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->givenToPiper() === [self::SORRY], 'sorry to be with Piper');
        await($session->stop());

        $this->assertSame([], $this->played);
        $this->assertSame([], glob("{$session->directory}/claude-*"));
    }

    public function testDoesNotSaySorryToSomeoneWhoOptedOutWhileItWasStillEncoded(): void
    {
        // Its ffmpeg has written the first of it, and takes its time over the rest: the voice library can't start yet.
        touch($hold = "{$this->recordings}/ffmpeg.hold");
        $this->setProcessEnv(['FAKE_WHISPER_EXIT' => '1', 'FAKE_FFMPEG_HOLD' => $hold, 'FAKE_FFMPEG_STARTS' => '1']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->givenToPiper() === [self::SORRY], 'sorry to be with Piper');
        $this->runFor(0.3);
        VoiceSession::optOut('555');
        unlink($hold);
        $this->runFor(0.5);

        $this->assertSame([], $this->played);
    }

    public function testWhatFailsBetweenPiperAndTheCallIsNotTakenForASentenceThatCannotBeSpoken(): void
    {
        $this->memory()->save(['555', '666'], 'They are planning a trip.');
        $this->inCall('555', '666');
        // Piper takes its time: the bot looks at who is in the channel again before it speaks the sentence.
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past four.'), 'FAKE_PIPER_DELAY' => '0.5']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->logged('Claude answered') !== [], 'Claude to answer');
        $this->voiceStates[] = $this->unreadable();
        $this->waitUntil(fn () => $this->played !== [], 'the call to be told');
        $this->inCall('555', '666');

        // Piper and the voice client both work, so the call can be told, and is.
        $this->assertSame('other', $this->logged('Voice reply failed: The voice states cannot be read')[0]['step']);
        $this->assertSame('Sorry, something went wrong with what was said. The bot\'s logs say why.', end($this->sent));
        $this->assertSame([self::SORRY], array_map(file_get_contents(...), $this->played));
    }

    public function testLeavesTheCallWhenItCanNoLongerKeepWhatIsSaid(): void
    {
        // Its voice client expects to be closed exactly once.
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);
        // Where what is being said is written is gone: a file is in the folder's place.
        touch("{$session->directory}/utterances");

        // Fifty bits of audio a second arrive, and each one fails. Thrown on, the first would have ended the bot.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => VoiceSession::forGuild(self::GUILD_ID) === null, 'the bot to leave the call');

        $failures = array_values(array_filter($this->loggedProblems(), fn (string $message) => str_starts_with($message, 'Something failed in the call: ')));
        $this->assertCount(3, $failures, 'It is not logged fifty times a second.');
        $this->assertStringContainsString('cannot open file for writing', $failures[0]);
        $this->assertNotEmpty(preg_grep('/UtteranceSplitter->push\(\)$/', $this->logged($failures[0])[0]['trace']));
        $this->assertContains('Leaving the call: the same thing has failed 3 times', $this->loggedProblems());
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame([VoiceSession::LEFT], $this->sent);

        await($session->stop());
        $this->assertSame([VoiceSession::LEFT], $this->sent, 'Nothing was said, so there is nothing to summarize.');
    }

    public function testLeavesTheCallWhenWhatIsSaidCanNoLongerBeHandedOn(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);
        $this->inCall('555');
        // The bot's cache of who is in the channel fails when it is read: every time someone has finished saying something.
        $this->voiceStates[] = $this->unreadable();
        $failed = fn (): int => count($this->logged('Something failed in the call: The voice states cannot be read'));

        foreach ([1, 2] as $utterance) {
            $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
            $this->waitUntil(fn () => $failed() === $utterance, 'the failure to be logged');
        }

        // Twice: the call goes on, as something may fail once.
        $this->assertSame($session, VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame([], $this->sent);
        $logged = $this->logged('Something failed in the call: The voice states cannot be read')[0];
        $this->assertInstanceOf(RuntimeException::class, $logged['exception']);
        $this->assertNotEmpty(preg_grep('/VoiceSession->queueUtterance\(\)$/', $logged['trace']));

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => VoiceSession::forGuild(self::GUILD_ID) === null, 'the bot to leave the call');

        // The third time, it is not expected to work again: the call would go on hearing nobody.
        $this->assertSame(3, $failed());
        $this->assertSame([VoiceSession::LEFT], $this->sent);
        $this->assertLogsNeverMention('what time is it');
    }

    public function testStillLeavesWhenWhatWasBeingSaidCannotBeKept(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);
        // Someone is in the middle of saying something when the call is stopped.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->voiceStates[] = $this->unreadable();

        await($session->stop());

        // The voice client was closed, which it expects: the bot is out of the channel.
        $this->assertSame(['Could not keep what was being said: The voice states cannot be read'], $this->loggedProblems());
        $this->assertCount(1, $this->logged('Voice session stopped'));
        $this->assertNotContains($session, VoiceSession::unfinished());
    }

    /**
     * Someone's voice state that fails when it is read, and with it the bot's look at who is in the channel.
     */
    private function unreadable(): object
    {
        return new class () {
            public function __get(string $name): never
            {
                throw new RuntimeException('The voice states cannot be read');
            }
        };
    }

    /**
     * The bot's caches of who people are, which fail on demand: see {@see $noNames}.
     */
    protected function userNames(array $names = self::MEMBERS + self::BOTS): object
    {
        return new class (parent::userNames($names), $this) {
            public function __construct(private object $names, private VoiceFailureTest $test)
            {
            }

            public function get(string $key, string $id): ?object
            {
                return $this->test->noNames === null ? $this->names->get($key, $id) : throw $this->test->noNames;
            }
        };
    }
}
