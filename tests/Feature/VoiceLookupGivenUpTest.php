<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Assistant\Lookups;
use App\Voice\VoiceSession;
use React\EventLoop\LoopInterface;
use Tests\Fixtures\ManualTimers;

/**
 * A lookup that takes too long in a call. The bot's timers only run out when the test says so.
 */
final class VoiceLookupGivenUpTest extends VoiceTestCase
{
    private const string QUESTION = 'Hey Claude, which PHP version is the latest?';

    private const string FAILED = "Sorry, I couldn't look that up.";

    private ManualTimers $timers;

    /** Claude's stand-in waits for this file before it looks something up, and deletes it. */
    private string $go;

    protected function setUp(): void
    {
        parent::setUp();
        $this->go = "{$this->recordings}/lookup.go";
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream("Let me look into that.\n", 'LOOK UP: Find the latest stable version of PHP.'),
            'FAKE_CLAUDE_OUTPUT_LOOKUP' => self::claudeResult('PHP 8.5.11 is the latest stable version.'),
            'FAKE_CLAUDE_LOOKUP_GO' => $this->go,
            'FAKE_WHISPER_OUTPUT' => self::QUESTION,
        ]);
    }

    protected function loop(): LoopInterface
    {
        return $this->timers ??= new ManualTimers();
    }

    public function testPostsAndSaysThatALookupTookTooLong(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        // The timer that notices the silence after an utterance is the test's to run, too.
        $this->runFor(1.1);
        $this->assertSame(1, $this->timers->elapse(0.25));
        $this->waitUntil(fn () => count($this->claudeCalls()) === 2 && count($this->played) === 1, 'the lookup to start');

        // A task that takes more than five minutes is given up.
        $this->assertSame(300.0, Lookups::TIMEOUT);
        $this->assertContains(300.0, $this->timers->pending());
        $this->assertSame(1, $this->timers->elapse(300.0));
        $this->waitUntil(fn () => count($this->sent) === 2 && count($this->played) === 2, 'the failure to be posted and said');

        $this->assertSame('> **Alice:** ' . self::QUESTION . "\n" . self::FAILED . ' (it took more than 5 minutes)', $this->sent[1]);
        $this->assertSame(self::FAILED, file_get_contents($this->played[1]));
        $this->assertStringEndsWith('] Claude: ' . self::FAILED . "\n", $this->transcript($session));
        $this->assertSame(['Could not look something up: it took more than 5 minutes'], $this->loggedProblems());
        $this->assertNotContains(300.0, $this->timers->pending());

        // Claude Code was stopped: its stand-in would go on now, and delete the file.
        $this->runFor(0.3);
        touch($this->go);
        $this->runFor(0.5);
        $this->assertFileExists($this->go);
        $this->assertCount(2, $this->sent, 'Nothing arrives later.');
        $this->assertSame([], $this->logged('Looked something up'));
    }
}
