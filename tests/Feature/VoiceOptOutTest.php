<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Analytics\Usage;
use App\Privacy\OptOuts;
use App\Voice\VoiceSession;
use Illuminate\Database\Capsule\Manager as DB;

use function React\Async\await;

/**
 * Calls with someone who opted out of being recorded. Alice never opts out; Bob does.
 */
final class VoiceOptOutTest extends VoiceTestCase
{
    public function testKeepsNothingOfSomeoneWhoOptedOutBeforeTheCall(): void
    {
        (new OptOuts())->add('666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        // Bob talks to Claude, and nothing happens: he isn't transcribed, so Claude never hears of it.
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $this->runFor(1.5);

        $this->assertSame('', $this->transcript($session));
        $this->assertFileDoesNotExist($this->claudeLog, 'Claude was not asked.');
        $this->assertDirectoryDoesNotExist("{$session->directory}/utterances", 'What he said never left the voice client.');
        $this->assertSame([], $this->sent);

        // Alice is recorded and answered as usual.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');
        await($session->stop());

        // The call's folder has her recording and nothing of Bob.
        $this->assertSame(
            ['555-1.wav', 'claude-2.ogg', 'summary.md', 'transcript.txt', 'utterances'],
            array_map(basename(...), glob("{$session->directory}/*")),
        );
        $this->assertWavDuration(1.0, "{$session->directory}/555-1.wav");
        $this->assertStringNotContainsString('Bob', $this->transcript($session));
        $this->assertStringNotContainsString('Bob', file_get_contents($this->claudeLog), 'The summary was made without him.');

        // The log says who was skipped, by ID, and he doesn't count as a speaker.
        $this->assertSame([['user' => '666']], array_map(fn (array $context) => array_slice($context, 2), $this->logged('Skipping a speaker who opted out')));
        $this->assertSame(['555'], array_column($this->logged('Recording a speaker'), 'user'));
        $this->assertSame(['555'], array_column($this->logged('Utterance ended'), 'user'));
        $this->assertSame(1, $this->logged('Voice session stopped')[0]['speakers']);

        // Neither do the statistics have anything of him.
        $this->assertSame([1, 1], [$this->usage()['speakers'], $this->usage()['utterances']]);
        $this->assertSame(0, DB::connection(Usage::CONNECTION)->table('events')->where('user_id', '666')->count());
        $this->assertSame([], $this->loggedProblems());
    }

    public function testDropsWhatSomeoneWasSayingWhenTheyOptedOut(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Sounds good.']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Bob opts out before he has finished talking.
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        VoiceSession::optOut('666');
        $this->runFor(1.5);

        $this->assertSame('', $this->transcript($session));
        $this->assertSame([], glob("{$session->directory}/utterances/*"), 'What he was saying is deleted.');
        $this->assertSame([], $this->logged('Utterance ended'));
        $this->assertSame(0, $this->usage()['utterances']);
        $this->assertSame([['user' => '666']], array_map(fn (array $context) => array_slice($context, 2), $this->logged('Skipping a speaker who opted out')));

        // His recording is deleted right away. The voice client goes on writing to it until the call
        // ends, but to a file that is no longer there.
        $this->assertFileDoesNotExist("{$session->directory}/666-1.wav");
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 0.5);
        await($session->stop());
        $this->assertSame(["{$session->directory}/utterances"], glob("{$session->directory}/*"));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testStopsAnsweringSomeoneWhoOptsOutWhileClaudeIsWriting(): void
    {
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past four. ', 'Time for a cup of tea.'),
            'FAKE_CLAUDE_PAUSE' => '10',
        ]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Bob opts out once the first sentence of his answer was spoken, while Claude is still writing the rest.
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');
        VoiceSession::optOut('666');
        touch($this->claudeResume);
        $this->waitUntil(fn () => $this->logged('Claude answered') !== [], 'Claude to finish');
        $this->runFor(0.5);

        // The rest isn't spoken, and the answer, which quotes him, is neither posted nor kept.
        $this->assertCount(1, $this->played);
        $this->assertSame(["{$session->directory}/claude-2.ogg"], glob("{$session->directory}/claude-*"));
        $this->assertSame([], $this->sent);
        $this->assertStringContainsString('] Bob: Hey Claude, what time is it?', $this->transcript($session), 'What he said before stays.');
        $this->assertStringNotContainsString('Claude:', $this->transcript($session));
        $this->assertSame([['user' => '666', 'reason' => 'they opted out']], array_map(fn (array $context) => array_slice($context, 2), $this->logged('Not answering')));
        $this->assertSame(0, $this->usage()['answers']);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testDropsWhatIsLeftOfSomeoneWhoOptsOutWhileTheCallEnds(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // The call stops while Bob is talking, and he opts out before what he said is transcribed.
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $ended = $session->stop();
        VoiceSession::optOut('666');
        await($ended);

        $this->assertSame('', $this->transcript($session));
        $this->assertFileDoesNotExist($this->claudeLog, 'There was nothing to summarize.');
        $this->assertSame([], $this->sent);
        $this->assertSame(["{$session->directory}/utterances"], glob("{$session->directory}/*"), 'His recording is deleted too.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testKeepsNothingOfASpeakerTheVoiceClientCannotName(): void
    {
        (new OptOuts())->add('666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Bob speaks, leaves the channel and comes back. His audio arrives again before the voice
        // gateway says whose it is, so the voice client only has his SSRC to name him by.
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 0.5);
        $vc->handleVoiceStateUpdate((object) ['user_id' => '666', 'channel_id' => null]);
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $this->runFor(1.5);

        // Whoever it is, they may have opted out, like Bob did.
        $this->assertSame([], glob("{$session->directory}/*.wav"));
        $this->assertSame('', $this->transcript($session));
        $this->assertFileDoesNotExist($this->claudeLog, 'Claude was not asked.');
        $this->assertSame([['ssrc' => 2]], array_map(fn (array $context) => array_slice($context, 2), $this->logged('Not recording a speaker the voice client cannot name')));
    }

    public function testDropsWhatWaitedToBeTranscribedWhenSomeoneOptedOut(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '1']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Both ask Claude something. Alice is answered first, and Bob opts out while he waits for his turn.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $this->waitUntil(fn () => is_file($this->claudeLog), 'Claude to be asked');
        $this->assertCount(1, glob("{$session->directory}/utterances/*"), 'What Bob said waits to be transcribed.');
        VoiceSession::optOut('666');
        // Right away, so nothing of him is left if the bot stops before it gets to it.
        $this->assertSame([], glob("{$session->directory}/utterances/*"), 'What he said is deleted.');
        $this->waitUntil(fn () => $this->sent !== [], 'Alice to be answered');
        $this->runFor(0.5);

        $this->assertCount(1, $this->logged('Transcribed'));
        $this->assertStringNotContainsString('Bob', $this->transcript($session));
        $this->assertStringContainsString('Answer Alice.', file_get_contents($this->claudeLog), 'Claude was only asked by Alice.');
        $this->assertCount(1, $this->sent);

        // Only the answer is slow, not the call's summary.
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0']);
    }

    public function testDoesNotTranscribeWhatWasDeletedWhenSomeoneOptsBackInBeforeItsTurn(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '1']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $this->waitUntil(fn () => is_file($this->claudeLog), 'Claude to be asked');
        VoiceSession::optOut('666');
        VoiceSession::optIn('666');
        $this->waitUntil(fn () => $this->sent !== [], 'Alice to be answered');
        $this->runFor(0.5);

        // What he said while he had opted out is gone, and nothing fails for it.
        $this->assertCount(1, $this->logged('Transcribed'));
        $this->assertStringNotContainsString('Bob', $this->transcript($session));
        $this->assertSame([], $this->loggedProblems());

        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0']);
    }

    public function testDropsWhatWasBeingTranscribedWhenSomeoneOptedOut(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_DELAY' => '1']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $this->waitUntil(fn () => $this->logged('Utterance ended') !== [], 'whisper to start');
        VoiceSession::optOut('666');
        $this->waitUntil(fn () => $this->logged('Transcribed') !== [], 'whisper to finish');

        // What whisper understood is thrown away.
        $this->assertSame('', $this->transcript($session));
        $this->assertFileDoesNotExist($this->claudeLog, 'Claude was not asked.');
        $this->assertSame([], $this->sent);
        $this->assertSame([], glob("{$session->directory}/utterances/*"));
    }

    public function testSkipsSomeoneWhoOptedOutBeforeTheySpokeInTheCall(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Sounds good.']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Bob opts out during the call, in whatever server, before he said anything in it.
        VoiceSession::optOut('666');
        $this->assertSame([], $this->logged('Skipping a speaker who opted out'), 'There is nothing of him to skip yet.');

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $this->waitUntil(fn () => $this->transcript($session) !== '', 'the transcript');
        $this->runFor(0.5);

        $this->assertSame(1, substr_count($this->transcript($session), 'Sounds good.'));
        $this->assertStringContainsString('] Alice: Sounds good.', $this->transcript($session));
        $this->assertSame([['user' => '666']], array_map(fn (array $context) => array_slice($context, 2), $this->logged('Skipping a speaker who opted out')));
    }
}
