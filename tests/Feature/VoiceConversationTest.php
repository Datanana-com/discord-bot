<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Settings\GuildSettings;
use App\Voice\VoiceSession;
use Monolog\Logger;
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
        $this->assertSame(["{$session->directory}/claude-2.ogg"], $this->played);
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
        $this->assertSame(['user' => '555', 'reason' => 'Claude was not addressed'], array_slice($this->logged('Not answering')[0], 2));
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

    public function testStartsWithTheServersSettings(): void
    {
        // This server answers everything, although .env has a wake word.
        (new GuildSettings(new Logger('test')))->save(self::GUILD_ID, [...GuildSettings::DEFAULTS, 'wake_word' => ''], '555');
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'What time is it?']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');

        $this->assertSame(["> **Alice:** What time is it?\nIt is a quarter past four."], $this->sent);
    }

    public function testKeepsItsSettingsWhenTheyChangeDuringTheCall(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Someone uses /settings while the call is running.
        (new GuildSettings(new Logger('test')))->save(self::GUILD_ID, [...GuildSettings::DEFAULTS, 'wake_word' => 'jarvis', 'model' => 'opus'], '555');

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');

        // The call still answers to "Claude", with the model it started with.
        $this->assertSame(["> **Alice:** Hey Claude, what time is it?\nIt is a quarter past four."], $this->sent);
        $this->assertStringContainsString("arg=--model\narg=haiku\n", file_get_contents($this->claudeLog));
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
        $this->assertSame('555', $this->logged('Voice reply failed: Claude Code: Not logged in · Please run /login')[0]['user']);
        $this->assertSame([1, 0], [$this->usage()['failures'], $this->usage()['answers']]);
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

    public function testLogsEachStepAndRecordsTheCallsUsage(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');
        $session->stop();

        // Each step is logged with the call's server and session, so one call can be followed in the log.
        $steps = array_filter($this->logs->getRecords(), fn ($record) => ($record->context['session'] ?? null) === $session->id);
        $this->assertSame(
            ['Voice session started', 'Recording a speaker', 'Utterance ended', 'Transcribed', 'Claude answered', 'Speaking the answer', 'Voice session stopped'],
            array_values(array_map(fn ($record) => $record->message, $steps)),
        );
        $this->assertSame(self::GUILD_ID, $this->logged('Voice session started')[0]['guild']);
        $this->assertSame(['user' => '555', 'ms' => 1000], array_slice($this->logged('Utterance ended')[0], 2));
        $this->assertSame(28, $this->logged('Transcribed')[0]['characters']);
        $this->assertSame(26, $this->logged('Claude answered')[0]['characters']);
        $this->assertSame(['speakers' => 1, 'utterances' => 1, 'answers' => 1, 'failures' => 0], array_slice($this->logged('Voice session stopped')[0], 3));

        // What was said stays in transcript.txt: it is never logged.
        foreach ($this->logs->getRecords() as $record) {
            $this->assertDoesNotMatchRegularExpression('/what time|quarter past/i', json_encode([$record->message, $record->context]));
        }

        // The call is counted for /stats.
        $usage = $this->usage();
        $this->assertSame(
            ['calls' => 1, 'speakers' => 1, 'utterances' => 1, 'speech_ms' => 1000, 'answers' => 1, 'failures' => 0],
            array_intersect_key($usage, array_flip(['calls', 'speakers', 'utterances', 'speech_ms', 'answers', 'failures'])),
        );
        $this->assertGreaterThan(0, $usage['answer_ms']);
        $this->assertGreaterThanOrEqual($usage['answer_ms'], $usage['call_ms']);
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
