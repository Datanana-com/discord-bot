<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Assistant\Lookups;
use App\Voice\VoiceSession;
use React\EventLoop\LoopInterface;
use Tests\Fixtures\ManualTimers;

/**
 * Lookups in a call and the bot's timers, which only run out when the test says so: the five
 * minutes a lookup gets, and the minute of quiet that closes a conversation.
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

    /**
     * The timer that ends an utterance once it fell silent is the bot's, so it only runs out here.
     */
    protected function waitUntil(callable $condition, string $what, float $timeout = 10.0): void
    {
        parent::waitUntil(function () use ($condition) {
            $this->timers->elapse(0.25);

            return $condition();
        }, $what, $timeout);
    }

    public function testTellingWhatWasLookedUpKeepsTheirConversationOpen(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Saying the wake word opened Alice's conversation, which a minute of quiet closes.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->claudeCalls()) === 2 && in_array(60.0, $this->timers->pending(), true), 'the lookup to start');

        // What was found arrives, and Claude is still telling it when that minute is over.
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('PHP 8.5.11 is the latest stable version. ', 'It is from late September.'),
            'FAKE_CLAUDE_PAUSE' => '10',
        ]);
        touch($this->go);
        $this->waitUntil(fn () => count($this->claudeCalls()) === 3 && count($this->played) === 2, 'Claude to start telling it');
        $this->assertSame(1, $this->timers->elapse(60.0));

        // It counts as answering her: her conversation stays open, and a minute starts again once it is told.
        $this->assertSame([], $this->logged('Conversation closed'));
        touch($this->claudeResume);
        $this->waitUntil(fn () => count($this->sent) === 3 && in_array(60.0, $this->timers->pending(), true), 'it to be told');
        $this->assertSame([], $this->logged('Conversation closed'));

        $this->assertSame(1, $this->timers->elapse(60.0));
        $this->assertSame(['quiet'], array_column($this->logged('Conversation closed'), 'reason'));
    }

    public function testPostsAndSaysThatALookupTookTooLong(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
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
