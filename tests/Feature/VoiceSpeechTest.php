<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Voice\VoiceSession;

use function React\Async\await;

/**
 * A call keeps one Piper process running, so that its voice is loaded once and not for every sentence.
 */
final class VoiceSpeechTest extends VoiceTestCase
{
    private const string ANSWER = 'Sure. It is a quarter past four. Time for a cup of tea. The kettle is already on.';

    /** The answer's sentences: "Sure." is too short to be spoken on its own. */
    private const array SENTENCES = ['Sure. It is a quarter past four.', 'Time for a cup of tea.', 'The kettle is already on.'];

    public function testOnePiperSpeaksEverySentenceOfACall(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream(self::ANSWER), 'FAKE_PIPER_LOG' => "{$this->recordings}/piper.log"]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Piper is started with the call, which is when it loads its voice, and writes into a folder of its own.
        $this->waitUntil(fn () => count($this->pipers()) === 1, 'Piper to be started');
        [$piper] = $this->pipers();
        $this->assertTrue($this->isRunning($piper));
        $this->assertSame(
            "arg=--model\narg={$this->recordings}/models/voice.onnx\narg=--output-dir\narg={$session->directory}/piper\n",
            file_get_contents("{$this->recordings}/piper.log"),
        );

        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 3, 'every sentence to be spoken');

        // Each sentence is saved as its own file next to the recordings, and they are spoken in order.
        $this->assertSame(
            ["{$session->directory}/claude-2.ogg", "{$session->directory}/claude-3.ogg", "{$session->directory}/claude-4.ogg"],
            $this->played,
        );
        $this->assertSame(self::SENTENCES, array_map(file_get_contents(...), $this->played));

        // So are those of the next answer, by the same Piper.
        $this->ask($vc, '666', 'Claude, say that again?');
        $this->waitUntil(fn () => count($this->played) === 6, 'every sentence to be spoken again');

        $this->assertSame([...self::SENTENCES, ...self::SENTENCES], array_map(file_get_contents(...), $this->played));
        $this->assertSame([$piper], $this->pipers());
        $this->assertTrue($this->isRunning($piper));

        // Nothing of Piper's own is left next to the recordings.
        $this->assertSame(['.', '..'], scandir("{$session->directory}/piper"));
        $this->assertSame([], glob("{$session->directory}/*.piper.wav"));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testPiperIsStartedAgainForTheNextSentenceWhenItStoppedByItself(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to be spoken');
        [$piper] = $this->pipers();

        // Piper ends by itself, between two answers.
        posix_kill($piper, SIGTERM);
        $this->waitUntil(fn () => ! $this->isRunning($piper), 'Piper to end');
        $this->assertSame([], $this->loggedProblems(), 'Nothing needs it until the next sentence.');

        $this->ask($vc, '666', 'Claude, are you still there?');
        $this->waitUntil(fn () => count($this->played) === 2, 'the next answer to be spoken');

        // The sentence was spoken by a Piper that was started for it, which is logged.
        $this->assertSame('It is a quarter past four.', file_get_contents($this->played[1]));
        $this->assertCount(2, $this->pipers());
        $this->assertSame(['Piper had stopped: starting it again'], $this->loggedProblems());
        $this->assertSame(['guild', 'session'], array_keys($this->logged('Piper had stopped: starting it again')[0]));

        // That one keeps running for the sentences after it.
        $this->ask($vc, '555', 'Claude, thank you.');
        $this->waitUntil(fn () => count($this->played) === 3, 'the last answer to be spoken');

        $this->assertCount(2, $this->pipers());
        $this->assertCount(1, $this->loggedProblems());
    }

    public function testAnFfmpegIsStartedAgainForTheNextSentenceWhenTheOneThatWaitedStopped(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->waitUntil(fn () => count($this->ffmpegs()) === 1, 'an ffmpeg to wait for the first sentence');

        // It ends by itself, long before anyone asks anything.
        posix_kill($this->ffmpegs()[0], SIGKILL);
        $this->waitUntil(fn () => ! $this->isRunning($this->ffmpegs()[0]), 'the ffmpeg to be gone');
        $this->runFor(0.2);
        $this->assertSame([], $this->loggedProblems(), 'Nothing is said, and nothing started, until a sentence needs one.');
        $this->assertCount(1, $this->ffmpegs());

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');

        $this->assertSame('It is a quarter past four.', file_get_contents($this->played[0]));
        $this->assertSame(['ffmpeg had stopped: starting it again'], $this->loggedProblems());
        $this->assertSame(1, $this->usage()['answers']);
        await($session->stop());
    }

    public function testASentencePiperFailsOnIsNotSpokenAndTheNextAnswerIs(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream(self::ANSWER), 'FAKE_PIPER_FAILS_ON' => 'cup of tea']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->loggedProblems() !== [], 'the failure to be logged');

        // The sentence Piper was working on when it stopped counts as failed, and nothing is spoken after it.
        $this->assertCount(1, $this->loggedProblems());
        $this->assertStringStartsWith('Voice reply failed: ', $this->loggedProblems()[0]);
        $this->assertStringEndsWith('fake-piper exited with code 1: The voice model could not be loaded.', $this->loggedProblems()[0]);
        $this->assertSame(["{$session->directory}/claude-2.ogg"], $this->played);
        $this->assertSame(["{$session->directory}/claude-2.ogg"], glob("{$session->directory}/claude-*"), 'No file is left of the sentence it failed on.');
        $this->assertDirectoryDoesNotExist("{$session->directory}/piper");
        $this->assertSame([1, 1], [$this->usage()['failures'], $this->usage()['answers']]);

        // The next answer is spoken, by a Piper started for its first sentence.
        $this->setProcessEnv(['FAKE_PIPER_FAILS_ON' => '', 'FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past four.')]);
        $this->ask($vc, '666', 'Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 2, 'the next answer to be spoken');

        $this->assertSame('It is a quarter past four.', file_get_contents($this->played[1]));
        $this->assertCount(2, $this->pipers());
        $this->assertSame('Piper had stopped: starting it again', $this->loggedProblems()[1]);
        $this->assertCount(2, $this->loggedProblems());
    }

    public function testPiperIsEndedWhenTheCallEnds(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->played) === 1, 'the answer to be spoken');
        [$piper] = $this->pipers();
        $this->assertTrue($this->isRunning($piper));

        await($session->stop());

        $this->waitUntil(fn () => ! $this->isRunning($piper), 'Piper to end');
        $this->assertDirectoryDoesNotExist("{$session->directory}/piper", 'Its folder goes with it.');
        $this->assertCount(1, $this->pipers());
        $this->assertSame([], $this->loggedProblems());
    }

    public function testACallIsOnlyOverOncePiperHasEnded(): void
    {
        // Nobody says anything, so there is nothing to summarize or remember when the call ends.
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);
        $this->waitUntil(fn () => count($this->pipers()) === 1, 'Piper to be started');

        $ended = $session->stop();
        $this->assertContains($session, VoiceSession::unfinished(), 'Piper has not ended yet, and its folder is in the call\'s.');

        await($ended);

        // Nothing of Piper is left in the call's folder, which may now be deleted when it is old enough.
        $this->assertFalse($this->isRunning($this->pipers()[0]));
        $this->assertDirectoryDoesNotExist("{$session->directory}/piper");
        $this->assertNotContains($session, VoiceSession::unfinished());
    }

    public function testPiperIsEndedWhenTheBotIsDisconnected(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->waitUntil(fn () => count($this->pipers()) === 1, 'Piper to be started');
        [$piper] = $this->pipers();

        // Someone disconnects the bot from the voice channel.
        $vc->emit('close');

        $this->waitUntil(fn () => ! $this->isRunning($piper), 'Piper to end');
        $this->assertCount(1, $this->pipers());
        $this->assertSame([], $this->loggedProblems());
    }
}
