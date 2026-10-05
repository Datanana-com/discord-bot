<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Settings\GuildSettings;
use App\Voice\VoiceSession;
use Monolog\Logger;
use RuntimeException;

use function React\Async\await;

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
        $this->assertStringContainsString("Alice: Hey Claude, what time is it?\n\nAlice is talking to you.", $this->claudeCalls()[0]['prompt']);

        // The answer is posted in the text chat and spoken into the call.
        $this->assertSame(["> **Alice:** Hey Claude, what time is it?\nIt is a quarter past four."], $this->sent);
        $this->assertSame(["{$session->directory}/claude-2.ogg"], $this->played);
        $this->assertSame('It is a quarter past four.', file_get_contents($this->played[0]), 'Piper was given the answer.');

        // Alice's speech is recorded, and the temporary utterance file is gone.
        await($session->stop());
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

    public function testAnswersAWakePhraseThatWhisperPunctuated(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => 'okay computer']);
        // Whisper writes a comma where Alice paused.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Okay, computer, what time is it?']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');

        $this->assertSame(["> **Alice:** Okay, computer, what time is it?\nIt is a quarter past four."], $this->sent);
    }

    public function testAnswersASpellingOfTheWakeWordThatWhisperWrote(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => 'claude, cloud']);
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Hey Cloud, what time is it?']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');

        $this->assertSame(["> **Alice:** Hey Cloud, what time is it?\nIt is a quarter past four."], $this->sent);
    }

    public function testDoesNotAnswerThatSpellingWhenOnlyTheNameIsTheWakeWord(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Hey Cloud, what time is it?']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->transcript($session) !== '', 'the transcript');
        $this->runFor(0.5);

        $this->assertFileDoesNotExist($this->claudeLog, 'Claude was not asked.');
        $this->assertSame([], $this->sent);
    }

    public function testGivesWhisperThePromptInEnvForEveryCall(): void
    {
        $this->setEnv(['WHISPER_PROMPT' => 'A voice call with the assistant Claude.']);
        $this->setProcessEnv(['FAKE_WHISPER_LOG' => "{$this->recordings}/whisper.log"]);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->assertStringContainsString("arg=--prompt
arg=A voice call with the assistant Claude.
", file_get_contents("{$this->recordings}/whisper.log"));
    }

    public function testGivesWhisperNoPromptByDefault(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_LOG' => "{$this->recordings}/whisper.log"]);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->assertStringNotContainsString('--prompt', file_get_contents("{$this->recordings}/whisper.log"));
    }

    public function testAnswersEverySpellingOfAServersWakeWord(): void
    {
        (new GuildSettings(new Logger('test')))->save(self::GUILD_ID, [...GuildSettings::DEFAULTS, 'wake_word' => 'jarvis, service, jarbas'], '555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        foreach (['Hey Jarvis, hi', 'Service, hi', 'Hello Jarbas, hi'] as $said) {
            $this->ask($vc, '555', $said);
        }

        $this->assertCount(3, $this->sent);
        $this->assertCount(3, $this->claudeCalls());
        $this->assertSame('jarvis, service, jarbas', $session->wakeWord);
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

        await($session->stop());
        $this->assertWavDuration(1.0, "{$session->directory}/555-1.wav");
        $this->assertWavDuration(2.0, "{$session->directory}/666-2.wav");
    }

    public function testDeletesTheCopiesTheVoiceClientMadeOfWhatWasSaidWhenTheCallEnds(): void
    {
        // The voice client's decoders copy each speaker's audio to the temp folder, named after when
        // they started and the speaker's SSRC. A decoder that is restarted starts a new copy.
        // SSRC 666003 is someone in another call, whose copy that call deletes when it ends.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Sounds good.']);
        $copies = array_map(fn (string $name) => sys_get_temp_dir() . "/{$name}", [date('Y-m-d_H-i') . '-1.ogg', '2026-10-04_21-07-2.ogg', date('Y-m-d_H-i') . '-2.ogg', date('Y-m-d_H-i') . '-666003.ogg']);
        array_map(touch(...), $copies);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        await($session->stop());

        $this->assertSame([false, false, false, true], array_map(is_file(...), $copies));
        unlink($copies[3]);
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
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => json_encode(['type' => 'result', 'is_error' => false, 'result' => 'Alice asked what time it was.'])]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        // Alice is still talking when the recording is stopped.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $ended = $session->stop();

        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertWavDuration(1.0, "{$session->directory}/555-1.wav");
        $this->assertSame('', $this->transcript($session));

        // What she said makes it into the transcript, but Claude no longer answers.
        await($ended);
        $this->assertStringEndsWith("] Alice: Hey Claude, what time is it?\n", $this->transcript($session));
        $this->assertSame('the session stopped', $this->logged('Not answering')[0]['reason']);
        $this->assertSame([], $this->played);

        // The call's summary waited for it: Claude was only asked to summarize, with what she said.
        $this->assertStringContainsString("] Alice: Hey Claude, what time is it?\n\nSummarize the call.\n", file_get_contents($this->claudeLog));
        $this->assertSame(['Alice asked what time it was.'], $this->sent);
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
        // Only the answer is slow, not the call's summary.
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0']);
        await($session->stop());

        // The answer is followed by the summary, which includes it. Claude's stand-in gives both the same text.
        $this->assertSame(["> **Alice:** Hey Claude, what time is it?\nIt is a quarter past four.", 'It is a quarter past four.'], $this->sent);
        $this->assertSame([], $this->played);
        $this->assertStringContainsString("] Claude: It is a quarter past four.\n\nSummarize the call.\n", file_get_contents($this->claudeLog));
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
        await($session->stop());

        // Each step is logged with the call's server and session, so one call can be followed in the log.
        $steps = array_filter($this->logs->getRecords(), fn ($record) => ($record->context['session'] ?? null) === $session->id);
        $this->assertSame(
            ['Voice session started', 'Recording a speaker', 'Utterance ended', 'Transcribed', 'Conversation opened', 'Claude answered', 'Started speaking', 'Voice session stopped', 'Summarized the call'],
            array_values(array_map(fn ($record) => $record->message, $steps)),
        );
        $this->assertSame(self::GUILD_ID, $this->logged('Voice session started')[0]['guild']);
        $this->assertSame(['user' => '555', 'ms' => 1000], array_slice($this->logged('Utterance ended')[0], 2));
        $this->assertSame(28, $this->logged('Transcribed')[0]['characters']);
        $this->assertSame(26, $this->logged('Claude answered')[0]['characters']);
        $this->assertSame(['speakers' => 1, 'utterances' => 1, 'answers' => 1, 'failures' => 0], array_slice($this->logged('Voice session stopped')[0], 3));
        $this->assertSame(['guild', 'session', 'ms', 'characters'], array_keys($this->logged('Summarized the call')[0]));
        $this->assertSame(26, $this->logged('Summarized the call')[0]['characters']);

        // What was said stays in transcript.txt, and the summary in summary.md: neither is ever logged.
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
