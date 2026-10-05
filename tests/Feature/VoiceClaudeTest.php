<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Voice\VoiceSession;

use function React\Async\await;

/**
 * How Claude Code is run for a call: what answers a question, and what summarizes and remembers the call.
 */
final class VoiceClaudeTest extends VoiceTestCase
{
    public function testOnlyTheAnswersOfACallAreWrittenWithoutThinking(): void
    {
        // Whoever runs the bot may have set how much Claude thinks.
        $this->setProcessEnv(['MAX_THINKING_TOKENS' => '4000']);
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        await($session->stop());

        // The answer, the call's summary, and the update of Alice's memory.
        $calls = $this->claudeCalls();
        $this->assertCount(3, $calls);
        $this->assertStringContainsString('Discord voice call', $calls[0]['system']);
        $this->assertSame('0', $calls[0]['thinking'], 'Thinking takes seconds before the first word of an answer.');
        $this->assertSame(['4000', '4000'], [$calls[1]['thinking'], $calls[2]['thinking']], 'Nobody waits for these.');

        // None of them loads the settings of the user the bot runs as.
        foreach ($calls as $call) {
            $this->assertStringEndsWith("arg=--setting-sources\narg=\n", $call['arguments']);
        }

        $this->assertSame([], $this->loggedProblems());
    }
}
