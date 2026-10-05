<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Settings\GuildSettings;
use App\Voice\VoiceSession;
use Monolog\Logger;
use React\Promise\Deferred;

use function React\Async\await;

final class VoiceSummaryTest extends VoiceTestCase
{
    private const string SUMMARY = "**Discussed**\n- Where to have lunch.\n\n**Decided**\n- Lunch at noon.\n\n**Action items**\n- Bob books a table.";

    protected function setUp(): void
    {
        parent::setUp();

        // Nobody talks to Claude in these calls, so it is only asked for their summary.
        $this->setProcessEnv([
            'FAKE_WHISPER_OUTPUT' => "Let's get lunch after this.",
            'FAKE_CLAUDE_OUTPUT' => json_encode(['type' => 'result', 'is_error' => false, 'result' => self::SUMMARY]),
        ]);
    }

    public function testPostsAndSavesASummaryWhenTheCallEnds(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->transcript($session) !== '', 'the transcript');
        $this->assertFileDoesNotExist($this->claudeLog, 'Nothing is summarized while the call goes on.');

        await($session->stop());

        // The summary is posted in the text chat and saved next to the transcript. It isn't spoken: the bot left the call.
        $this->assertSame([self::SUMMARY], $this->sent);
        $this->assertSame(self::SUMMARY . "\n", file_get_contents("{$session->directory}/summary.md"));
        $this->assertSame([], $this->played);

        // Claude got the transcript, and was asked for a summary of it instead of a spoken reply.
        $claudeCall = file_get_contents($this->claudeLog);
        $this->assertStringContainsString(
            "stdin=Transcript of the voice call:\n\n" . trim($this->transcript($session)) . "\n\nSummarize the call.\n",
            $claudeCall,
        );
        $this->assertStringContainsString("arg=--system-prompt\narg=You summarize Discord voice calls.", $claudeCall);
        $systemPrompt = preg_replace('/\s+/', ' ', $claudeCall);
        $this->assertStringContainsString('what was discussed, what was decided, and the action items with who took them', $systemPrompt);
        $this->assertStringContainsString('Only include what the transcript says', $systemPrompt);
        $this->assertStringContainsString('in the language the call was held in', $systemPrompt);
        $this->assertStringNotContainsString('read aloud', $systemPrompt, 'The system prompt for spoken replies is not used.');

        $this->assertSame([], $this->loggedProblems());
    }

    public function testTheServersModelWritesTheSummary(): void
    {
        // .env has haiku.
        (new GuildSettings(new Logger('test')))->save(self::GUILD_ID, [...GuildSettings::DEFAULTS, 'model' => 'opus'], '555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        await($session->stop());

        $this->assertSame([self::SUMMARY], $this->sent);
        $this->assertStringContainsString("arg=--model\narg=opus\n", file_get_contents($this->claudeLog));
    }

    public function testSummarizesTheWholeTranscriptOfALongCall(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // As if the call had been going on for a while: to answer, Claude only gets its last 20 lines.
        $earlier = implode('', array_map(fn (int $minute) => sprintf("[10:%02d:00] Bob: Point %d.\n", $minute, $minute), range(1, 30)));
        file_put_contents("{$session->directory}/transcript.txt", $earlier);
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);

        await($session->stop());

        $transcript = $this->transcript($session);
        $this->assertSame(31, substr_count($transcript, "\n"));
        $this->assertStringEndsWith("] Alice: Let's get lunch after this.\n", $transcript);

        $claudeCall = file_get_contents($this->claudeLog);
        $this->assertStringContainsString("stdin=Transcript of the voice call:\n\n[10:01:00] Bob: Point 1.\n", $claudeCall);
        $this->assertStringContainsString(trim($transcript) . "\n\nSummarize the call.\n", $claudeCall);
    }

    public function testSummarizesACallThatEndsBecauseTheBotWasDisconnected(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->transcript($session) !== '', 'the transcript');
        $vc->emit('close');

        // The call already stopped: stopping it again only waits for it to end.
        await($session->stop());

        $this->assertSame([self::SUMMARY], $this->sent);
        $this->assertFileExists("{$session->directory}/summary.md");
    }

    public function testSummarizesACallThatStopsWhileAnAnswerIsBeingSpoken(): void
    {
        // Like the real voice client, which never says an answer finished when it is closed while speaking it.
        $this->playing = (new Deferred())->promise();
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Hey Claude, what time is it?']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');

        // Bob says something over the answer, and is still talking when the call stops.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => "Let's stop here."]);
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $ended = $session->stop();
        $this->waitUntil(fn () => count($this->sent) === 2, 'the summary', timeout: 3.0);
        await($ended);

        $this->assertStringEndsWith("] Bob: Let's stop here.\n", $this->transcript($session));
        $this->assertStringContainsString("] Bob: Let's stop here.\n\nSummarize the call.\n", file_get_contents($this->claudeLog));
        $this->assertSame(self::SUMMARY, $this->sent[1] ?? null);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testSplitsASummaryThatDoesNotFitInOneMessage(): void
    {
        $summary = $this->longSummary();
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => json_encode(['type' => 'result', 'is_error' => false, 'result' => $summary])]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        await($session->stop());

        // Two messages, in order, each ending with a whole line: together they are the summary.
        $this->assertGreaterThan(2000, mb_strlen($summary));
        $this->assertCount(2, $this->sent);
        $this->assertLessThanOrEqual(2000, max(array_map(mb_strlen(...), $this->sent)));
        $this->assertSame($summary, implode("\n", $this->sent));
        $this->assertSame($summary . "\n", file_get_contents("{$session->directory}/summary.md"));
    }

    public function testPostsTheNextPartOfASummaryOnceThePreviousOneArrived(): void
    {
        $arriving = new Deferred();
        $this->sending = $arriving->promise();
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => json_encode(['type' => 'result', 'is_error' => false, 'result' => $this->longSummary()])]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $over = false;
        $ended = $session->stop()->then(function () use (&$over) {
            $over = true;
        });
        $this->waitUntil(fn () => $this->sent !== [], 'the first part of the summary');

        // Discord hasn't accepted the first part yet: the second one waits, so they can't arrive out of order.
        $this->assertCount(1, $this->sent);
        $this->assertFalse($over, 'The call is not over before its summary is posted.');

        $arriving->resolve(null);
        await($ended);

        $this->assertCount(2, $this->sent);
    }

    public function testTellsTheChannelWhenTheSummaryIsEmpty(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => json_encode(['type' => 'result', 'is_error' => false, 'result' => " \n"])]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        await($session->stop());

        // Discord refuses empty messages, so an empty summary would otherwise go missing without a word.
        $this->assertSame(["Sorry, I couldn't summarize the call. (Claude gave an empty summary.)"], $this->sent);
        $this->assertSame(['Could not summarize the call: Claude gave an empty summary.'], $this->loggedProblems());
        $this->assertSame([], $this->logged('Summarized the call'));
        $this->assertFileDoesNotExist("{$session->directory}/summary.md");
    }

    public function testDoesNotAskClaudeWhenNothingWasSaid(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => '']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Someone made noise, but whisper heard no words in it.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        await($session->stop());

        $this->assertSame(0, $this->logged('Transcribed')[0]['characters'], 'The noise was transcribed before the call ended.');
        $this->assertSame('', $this->transcript($session));
        $this->assertFileDoesNotExist($this->claudeLog, 'Claude was not asked.');
        $this->assertSame([], $this->sent);
        $this->assertFileDoesNotExist("{$session->directory}/summary.md");
    }

    public function testTellsTheChannelWhenTheCallCannotBeSummarized(): void
    {
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => json_encode(['type' => 'result', 'is_error' => true, 'result' => 'Usage limit reached']),
            'FAKE_CLAUDE_EXIT' => '1',
        ]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        await($session->stop());

        $this->assertSame(["Sorry, I couldn't summarize the call. (Claude Code: Usage limit reached)"], $this->sent);
        $this->assertSame(['Could not summarize the call: Claude Code: Usage limit reached'], $this->loggedProblems());
        $this->assertTrue($this->logs->hasWarningThatContains('Could not summarize the call'));
        $this->assertFileDoesNotExist("{$session->directory}/summary.md");

        // The call itself ended like any other: the bot left, and the recording and transcript are complete.
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertCount(1, $this->logged('Voice session stopped'));
        $this->assertWavDuration(1.0, "{$session->directory}/555-1.wav");
        $this->assertStringEndsWith("] Alice: Let's get lunch after this.\n", $this->transcript($session));
        $this->assertSame(1, $this->usage()['calls']);
    }

    /**
     * A summary of 30 lines and 3509 characters: 17 lines fit in a Discord message.
     */
    private function longSummary(): string
    {
        $lines = array_map(fn (int $point) => sprintf('- Point %02d: %sand that was it.', $point, str_repeat('and so on, ', 8)), range(1, 30));

        return implode("\n", $lines);
    }
}
