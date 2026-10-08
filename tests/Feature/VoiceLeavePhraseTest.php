<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Settings\GuildSettings;
use App\Voice\VoiceSession;
use Monolog\Logger;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use Tests\Fixtures\ManualTimers;

use function React\Async\await;

/**
 * Saying the leave phrase ends the call, as /stop would, after the bot said "Okay.". The bot's timers only
 * run out when a test says so.
 *
 * Alice, Bob and Carol are 555, 666 and 777. Whatever whisper hears in a test is what the last one
 * to speak said, so nothing here lets two people talk before their first sentence is transcribed.
 */
final class VoiceLeavePhraseTest extends VoiceTestCase
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

    public function testTheLeavePhraseEndsTheCallTheWayStopDoes(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT_MEMORY' => $this->claudeSays('- Updated by Claude.')]);
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 1 && $this->waitingClaudes() !== [] && $this->pipers() !== [], 'the answer to be spoken');
        $processes = [...$this->waitingClaudes(), ...$this->pipers()];

        $this->says($vc, '555', 'Disconnect, Claude.');
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
        $this->assertCount(1, array_filter($this->claudeCalls(), fn (array $call) => str_contains($call['prompt'], 'said this to you just now')));
        $this->assertSame([['user' => '555']], $this->contexts('Ended by the leave phrase'));
        $this->assertCount(1, $this->logged('Voice session stopped'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAnyoneInTheCallCanSayTheLeavePhraseWithoutHavingAskedAnything(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->hearsNoAnswer($vc, $session, '555', 'We should get going.');

        // Carol asked nothing before, and it is no question either.
        $this->says($vc, '777', 'Disconnect Claude.');
        $this->callEnds($session);

        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame('Okay.', file_get_contents($this->played[0]));
        $this->assertSame('Carol ended the call by voice.', $this->sent[0]);
        $this->assertSame([['user' => '777']], $this->contexts('Ended by the leave phrase'));
        // Only the summary and the memories were asked of Claude.
        $this->assertSame([], array_filter($this->claudeCalls(), fn (array $call) => str_contains($call['prompt'], 'said this to you just now')));
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

        $this->says($vc, '555', 'Okay, disconnect computer.');
        $this->callEnds($session);

        $this->assertSame([['user' => '555']], $this->contexts('Ended by the leave phrase'));
    }

    public function testTheLeavePhraseIsHeardForEverySpellingOfTheWakeWord(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => 'claude, cloud, claud']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->assertSame('disconnect claude, disconnect cloud, disconnect claud', $session->leavePhrase);

        // Whisper writes "Claude" as "Claud" when it mishears it, in the leave phrase too.
        $this->says($vc, '555', 'Disconnect, claud.');
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
        $this->ask($vc, '555', 'Disconnect computer, will you?');
        $this->assertSame($session, VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame([], $this->logged('Ended by the leave phrase'));

        $this->says($vc, '555', 'Hang up on, Claude.');
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
        $this->says($vc, '555', 'Hang up.');
        $this->callEnds($session);

        $this->assertSame([['user' => '555']], $this->contexts('Ended by the leave phrase'));
        $this->assertCount(1, array_filter($this->claudeCalls(), fn (array $call) => str_contains($call['prompt'], 'said this to you just now')), 'Only the question was answered.');
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
        $this->ask($vc, '555', 'They disconnected Claude again.');
        $this->ask($vc, '555', 'Claude, I got disconnected.');
        $this->ask($vc, '555', 'Claude, will you disconnect?');
        $this->ask($vc, '555', 'Please disconnect from Claude now.');

        $this->runFor(0.5);
        $this->assertSame($session, VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame([], $this->logged('Ended by the leave phrase'));
        $this->assertCount(4, $this->played, 'Only the four answers were said.');
    }

    public function testASentenceThatHoldsTheLeavePhraseEndsTheCallWhateverElseItSays(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // The docs say so: "don't stop Claude" is not answered in the same way.
        $this->says($vc, '555', 'Please, don\'t disconnect Claude!');
        $this->callEnds($session);

        $this->assertSame([['user' => '555']], $this->contexts('Ended by the leave phrase'));
    }

    public function testTheLeavePhraseIsCheckedBeforeTheStopPhrase(): void
    {
        // A stop phrase someone set that holds the leave phrase: it would only keep the sentence from Claude.
        $this->setEnv(['VOICE_STOP_PHRASE' => 'disconnect claude, stop claude']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->says($vc, '555', 'Disconnect Claude.');
        $this->callEnds($session);

        $this->assertSame([['user' => '555']], $this->contexts('Ended by the leave phrase'));
        $this->assertNotContains('the stop phrase', array_column($this->logged('Not answering'), 'reason'), 'It was not taken for the stop phrase.');
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
        $this->says($vc, '666', 'Disconnect Claude.');
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
        $this->says($vc, '555', 'Disconnect Claude.');
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

        $this->says($vc, '555', 'Disconnect Claude.');
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

    public function testTheCallCanEndWhileOkayIsBeingSpoken(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // The voice client never says "Okay." was spoken when it is closed while speaking it.
        $this->playing = (new Deferred())->promise();
        $this->says($vc, '555', 'Disconnect Claude.');
        $this->waitUntil(fn () => count($this->played) === 1, 'okay to be spoken');
        // /stop, or someone disconnecting the bot, ends it first: the turn is over, and nothing is left waiting.
        await($session->stop());

        $this->assertSame([], $this->logged('Ended by the leave phrase'));
        $this->assertNotContains('Alice ended the call by voice.', $this->sent);
        $this->assertSame([], $this->timers->pending());
        $this->assertSame([], $this->loggedProblems());
    }

    public function testLeavesAllTheSameWhenOkayCannotBeSpoken(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);
        $this->setProcessEnv(['FAKE_PIPER_FAILS_ON' => 'Okay.']);

        $this->says($vc, '555', 'Disconnect Claude.');
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
        $this->says($vc, '555', 'Disconnect Claude.');
        // Transcribed, so it has been heard and okay is being made, which takes half a second.
        $this->waitUntil(fn () => str_contains($this->transcript($session), 'Alice: Disconnect Claude.') && count($this->givenToPiper()) === 1, 'it to be transcribed, and okay to be with Piper');
        // /stop, or someone disconnecting the bot, ends it first.
        await($session->stop());
        $this->runFor(0.5);

        $this->assertSame([], $this->logged('Ended by the leave phrase'));
        $this->assertSame([], $this->played, '"Okay." is not said in a call that ended.');
        $this->assertSame([], glob("{$session->directory}/claude-*"), 'An okay nobody heard is not kept.');
        $this->assertSame([self::ANSWER], $this->sent, 'Only the summary was posted.');
        $this->assertCount(1, $this->logged('Voice session stopped'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testDoesNotSayOkayToSomeoneWhoOptedOutWhileItWasStillEncoded(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // The ffmpeg of "Okay." has written the first of it, and takes its time over the rest: the voice library can't start yet.
        touch($hold = "{$this->recordings}/ffmpeg.hold");
        $this->setProcessEnv(['FAKE_FFMPEG_HOLD' => $hold, 'FAKE_FFMPEG_STARTS' => '1']);
        $this->says($vc, '555', 'Disconnect Claude.');
        $this->waitUntil(fn () => count($this->givenToPiper()) === 1, 'okay to be with Piper');
        $this->runFor(0.3);
        VoiceSession::optOut('555');
        unlink($hold);
        $this->callEnds($session);

        // They had said it before they opted out, so the call ends. Nothing is said to them any more.
        $this->assertSame([], $this->played);
        $this->assertSame([], glob("{$session->directory}/claude-*"), 'An okay nobody heard is not kept.');
        $this->assertSame([['user' => '555']], $this->contexts('Ended by the leave phrase'));
    }

    public function testTheLogOfTheLeavePhraseHoldsNoIdsButTheirsAndNeverWhatWasSaid(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->says($vc, '555', 'Disconnect Claude.');
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
}
