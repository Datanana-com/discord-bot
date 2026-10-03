<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Voice\VoiceSession;
use Discord\Voice\Rtp\UDP;
use Discord\Voice\VoiceClient;
use RuntimeException;

final class VoiceConversationTest extends VoiceTestCase
{
    public function testAnswersWhenSomeoneTalksToClaude(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');

        // The question and the answer are both in the transcript.
        $this->assertMatchesRegularExpression(
            '/^\[\d\d:\d\d:\d\d\] Alice: Hey Claude, what time is it\?\n\[\d\d:\d\d:\d\d\] Claude: It is a quarter past four\.\n$/',
            $this->transcript($session),
        );

        // Claude got the conversation so far and knows who is talking to it.
        $claudeCall = file_get_contents($this->claudeLog);
        $this->assertStringContainsString("Alice: Hey Claude, what time is it?\n\nAlice is talking to you.", $claudeCall);

        // The answer is posted in the text chat and spoken into the call.
        $this->assertSame(["> **Alice:** Hey Claude, what time is it?\nIt is a quarter past four."], $this->sent);
        $this->assertSame(["{$session->directory}/claude-2.wav"], $this->played);
        $this->assertSame('It is a quarter past four.', file_get_contents($this->played[0]), 'Piper was given the answer.');

        // Alice's speech is recorded, and the temporary utterance file is gone.
        $session->stop();
        $this->assertWavDuration(1.0, "{$session->directory}/555-1.wav");
        $this->assertSame([], glob("{$session->directory}/utterances/*"));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testOnlyTranscribesWhenNobodyTalksToClaude(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => "Let's get lunch after this."]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->transcript($session) !== '', 'the transcript');
        $this->runFor(0.5);

        $this->assertStringEndsWith("] Alice: Let's get lunch after this.\n", $this->transcript($session));
        $this->assertFileDoesNotExist($this->claudeLog, 'Claude was not asked.');
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->played);
    }

    public function testAnswersEverythingWithoutAWakeWord(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => '']);
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'What time is it?']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');

        $this->assertSame(["> **Alice:** What time is it?\nIt is a quarter past four."], $this->sent);
    }

    public function testIgnoresSpeechThatIsTooShort(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 0.2);
        $this->runFor(1.5);

        $this->assertSame('', $this->transcript($session));
        $this->assertSame([], $this->sent);
    }

    public function testRecordsEachSpeakerSeparately(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Sounds good.']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 2.0);
        $this->waitUntil(fn () => substr_count($this->transcript($session), 'Sounds good.') === 2, 'both speakers to be transcribed');

        $this->assertStringContainsString('] Alice: Sounds good.', $this->transcript($session));
        $this->assertStringContainsString('] Bob: Sounds good.', $this->transcript($session));

        $session->stop();
        $this->assertWavDuration(1.0, "{$session->directory}/555-1.wav");
        $this->assertWavDuration(2.0, "{$session->directory}/666-2.wav");
    }

    public function testTellsTheChannelWhenClaudeCannotAnswer(): void
    {
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => json_encode(['type' => 'result', 'is_error' => true, 'result' => 'Not logged in · Please run /login']),
            'FAKE_CLAUDE_EXIT' => '1',
        ]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->sent !== [], 'a message in the channel');

        $this->assertSame(["Sorry, I couldn't get an answer from Claude. (Claude Code: Not logged in · Please run /login)"], $this->sent);
        $this->assertSame([], $this->played);
        $this->assertStringContainsString('] Alice: Hey Claude, what time is it?', $this->transcript($session));
        $this->assertSame(['Voice reply failed: Claude Code: Not logged in · Please run /login'], $this->loggedProblems());
    }

    public function testStopLeavesAndStillTranscribesUnfinishedSpeech(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        // Alice is still talking when the recording is stopped.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $session->stop();

        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertWavDuration(1.0, "{$session->directory}/555-1.wav");

        // What she said makes it into the transcript, but Claude no longer answers.
        $this->waitUntil(fn () => $this->transcript($session) !== '', 'the transcript');
        $this->runFor(0.5);
        $this->assertStringEndsWith("] Alice: Hey Claude, what time is it?\n", $this->transcript($session));
        $this->assertFileDoesNotExist($this->claudeLog);
        $this->assertSame([], $this->sent);
    }

    public function testStopsWhenDisconnectedFromTheCall(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $vc->emit('close');

        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
    }

    public function testIgnoresWhatWhisperHearsAsBlankAudio(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => '']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $utterances = "{$session->directory}/utterances";
        $this->waitUntil(fn () => is_dir($utterances) && glob("{$utterances}/*") === [], 'the utterance to be transcribed');

        $this->assertSame('', $this->transcript($session));
        $this->assertFileDoesNotExist($this->claudeLog);
        $this->assertSame([], $this->sent);
    }

    public function testPostsButDoesNotSpeakAnAnswerThatArrivesAfterStop(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '1']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => is_file($this->claudeLog), 'Claude to be asked');
        $session->stop();
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        $this->assertSame(["> **Alice:** Hey Claude, what time is it?\nIt is a quarter past four."], $this->sent);
        $this->assertSame([], $this->played);
    }

    public function testStillSpeaksAnswersThatCannotBePosted(): void
    {
        $this->sendError = new RuntimeException('Missing Send Messages permission');
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');

        $this->assertSame(['Could not post in the text channel: Missing Send Messages permission'], $this->loggedProblems());
    }

    public function testRecordsButCannotAnswerSpeakersWithoutAReceiveStream(): void
    {
        $session = VoiceSession::start(
            $vc = $this->voiceClient($channel = $this->voiceChannel(), findsReceiveStreams: false),
            $channel,
            $this->discord,
        );

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->runFor(1.5);
        $session->stop();

        $this->assertSame('', $this->transcript($session));
        $this->assertSame(['No receive stream for 555; their speech will not be answered.'], $this->loggedProblems());
        $this->assertWavDuration(1.0, "{$session->directory}/555-1.wav");
    }

    public function testSendsSilenceSoDiscordSendsItTheCallsAudio(): void
    {
        $session = VoiceSession::start($this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        $this->assertSame(array_fill(0, 5, UDP::SILENCE_FRAME), $this->sentFrames);
        $this->assertSame([VoiceClient::MICROPHONE, VoiceClient::NOT_SPEAKING], $this->speakingUpdates, 'It announced the audio, and that it ended.');
        $session->stop();
    }

    public function testDoesNotSendSilenceWhileSpeaking(): void
    {
        // As when the next silence is due while an answer plays.
        $vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true);
        $vc->speaking = VoiceClient::MICROPHONE;

        $session = VoiceSession::start($vc, $channel, $this->discord);

        $this->assertSame([], $this->sentFrames);
        $this->assertSame([], $this->speakingUpdates);
        $session->stop();
    }

    public function testStopIsSafeToCallTwice(): void
    {
        // The voice client expects to be closed exactly once.
        $session = VoiceSession::start($this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        $session->stop();
        $session->stop();

        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
    }

    public function testWarnsWhenTheRecordingCannotBeStoppedCleanly(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $vc->stopRecording();

        $session->stop();

        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame(['Could not stop recording cleanly: Not recording audio.'], $this->loggedProblems());
    }
}
