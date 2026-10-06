<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Voice\VoiceSession;
use LogicException;
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
        $this->voiceStates[] = new class () {
            public function __get(string $name): never
            {
                throw new RuntimeException('The voice states cannot be read');
            }
        };
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
        $this->voiceStates[] = new class () {
            public function __get(string $name): never
            {
                throw new RuntimeException('The voice states cannot be read');
            }
        };

        await($session->stop());

        // The voice client was closed, which it expects: the bot is out of the channel.
        $this->assertSame(['Could not keep what was being said: The voice states cannot be read'], $this->loggedProblems());
        $this->assertCount(1, $this->logged('Voice session stopped'));
        $this->assertNotContains($session, VoiceSession::unfinished());
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
