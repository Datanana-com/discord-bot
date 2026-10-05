<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Analytics\Usage;
use App\Commands\Global\PrivacyCommand;
use App\Commands\Global\ShareCommand;
use App\Commands\Global\UnshareCommand;
use App\Privacy\OptOuts;
use App\Settings\UserSettings;
use App\Voice\VoiceSession;
use Discord\Parts\Channel\Channel;
use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Commands\CommandTestCase;

use function React\Async\await;

/**
 * /privacy in calls: someone who chose "only after /share" has their personal memory used in a call with
 * other people only once they shared it. Alice is 555, Bob 666 and Carol 777; the bot is 999.
 */
final class VoicePrivacyTest extends CommandTestCase
{
    private const string ALICE = '- Is building a game called Bananas, and wants it out by spring.';

    private const string BOB = '- Is learning to sail, and wants the game to be multiplayer.';

    private const string TRIP = '- Alice and Bob plan a trip to Lisbon.';

    private const string QUESTION = 'Hey Claude, what should I do next?';

    protected function setUp(): void
    {
        parent::setUp();

        $this->memory()->save('555', self::ALICE);
        $this->memory()->save('666', self::BOB);
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT_MEMORY' => $this->claudeSays('- Updated by Claude.')]);
    }

    public function testAQuestionInACallWithOthersLeavesOutThePersonalMemoryOfSomeoneWhoChoseToShareItFirst(): void
    {
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->privacy('555', UserSettings::AFTER_SHARE);

        $this->ask($vc, '555', self::QUESTION);
        $this->ask($vc, '666', self::QUESTION);

        // Alice gets the memory of her group with Bob, which isn't hers alone. Bob chose nothing: he still gets his own.
        $this->assertSame(
            "What you remember about Alice and Bob together:\n\n" . self::TRIP . "\n\n"
            . "Transcript of the voice call so far:\n\nAlice: " . self::QUESTION . "\n\nAlice is talking to you. Reply to their last message.",
            $this->claudeCalls()[0]['prompt'],
        );
        $this->assertSame(
            "What you remember about Bob:\n\n" . self::BOB . "\n\n"
            . "What you remember about Alice and Bob together:\n\n" . self::TRIP . "\n\n"
            . "Transcript of the voice call so far:\n\nAlice: " . self::QUESTION . "\nClaude: It is a quarter past four.\nBob: " . self::QUESTION . "\n\nBob is talking to you. Reply to their last message.",
            $this->untimed($this->claudeCalls()[1]['prompt']),
        );
        $this->assertStringNotContainsString('Bananas', $this->claudeCalls()[1]['prompt']);
        $this->assertSame([], $this->loggedProblems());
        $this->assertLogsNeverMention('Bananas', 'multiplayer', 'Lisbon', 'what should I do next');
        await($session->stop());
    }

    public function testTheDefaultKeepsUsingItWheneverTheyAsk(): void
    {
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', self::QUESTION);

        $this->assertStringStartsWith("What you remember about Alice:\n\n" . self::ALICE . "\n\n", $this->claudeCalls()[0]['prompt']);

        // Choosing the default again is the same.
        $this->privacy('555', UserSettings::AFTER_SHARE);
        $this->privacy('555', UserSettings::WHEN_ASKED);
        $this->ask($vc, '555', self::QUESTION);

        $this->assertStringStartsWith("What you remember about Alice:\n\n" . self::ALICE . "\n\n", $this->claudeCalls()[1]['prompt']);
        await($session->stop());
    }

    public function testAlonePersonalMemoryIsStillUsed(): void
    {
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->privacy('555', UserSettings::AFTER_SHARE);

        $this->ask($vc, '555', self::QUESTION);

        // Nobody else is there to hear it.
        $this->assertSame(
            "What you remember about Alice:\n\n" . self::ALICE . "\n\n"
            . "Transcript of the voice call so far:\n\nAlice: " . self::QUESTION . "\n\nAlice is talking to you. Reply to their last message.",
            $this->claudeCalls()[0]['prompt'],
        );
        $this->assertSame([], $this->loggedProblems());
        await($session->stop());
    }

    public function testShareStillAddsItWhateverTheSetting(): void
    {
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->privacy('555', UserSettings::AFTER_SHARE);

        $this->share('555', $channel);
        $this->ask($vc, '555', self::QUESTION);
        $this->ask($vc, '666', self::QUESTION);

        // Sharing is an explicit choice for this call: it is Alice's own memory when she asks, and labeled when Bob does.
        $this->assertStringStartsWith("What you remember about Alice:\n\n" . self::ALICE . "\n\n", $this->claudeCalls()[0]['prompt']);
        $this->assertStringNotContainsString('who shared', $this->claudeCalls()[0]['prompt']);
        $this->assertStringContainsString("What you remember about Alice, who shared their memory with this call:\n\n" . self::ALICE . "\n\n", $this->claudeCalls()[1]['prompt']);
        $this->assertSame(['Alice shared their memory with this call.'], array_values(array_filter($this->sent, fn (string $message) => ! str_starts_with($message, '> '))));

        // Taking it back puts the setting back in charge.
        $this->unshare('555');
        $this->ask($vc, '555', self::QUESTION);
        $this->ask($vc, '666', self::QUESTION);

        $calls = $this->claudeCalls();
        $this->assertStringNotContainsString('Bananas', $calls[2]['prompt']);
        $this->assertStringNotContainsString('Bananas', $calls[3]['prompt']);
        $this->assertSame([], $this->loggedProblems());
        await($session->stop());
    }

    public function testAChangeCountsFromTheNextQuestionInACallThatIsGoingOn(): void
    {
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', self::QUESTION);
        $this->privacy('555', UserSettings::AFTER_SHARE);
        $this->ask($vc, '555', self::QUESTION);
        $this->privacy('555', UserSettings::WHEN_ASKED);
        $this->ask($vc, '555', self::QUESTION);

        $this->assertSame(
            [true, false, true],
            array_map(fn (array $call) => str_contains($call['prompt'], 'Bananas'), $this->claudeCalls()),
        );
        await($session->stop());
    }

    /**
     * @param callable(self): void $break
     */
    #[DataProvider('unreadableSettings')]
    public function testItFailsClosedWhenTheSettingCannotBeRead(callable $break, string $why): void
    {
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->privacy('555', UserSettings::AFTER_SHARE);
        $break($this);

        $this->ask($vc, '555', self::QUESTION);
        $this->share('555', $channel);
        $this->ask($vc, '555', self::QUESTION);

        // Not knowing what Alice chose, her memory is left out of a call with others, and says why. Sharing it is still her choice.
        $this->assertStringNotContainsString('Bananas', $this->claudeCalls()[0]['prompt']);
        $this->assertStringContainsString('Bananas', $this->claudeCalls()[1]['prompt']);
        $this->assertContains($why, $this->loggedProblems());
        $this->assertSame('555', $this->logged($why)[0]['user']);
        await($session->stop());
    }

    /**
     * @return array<string, array{callable(self): void, string}>
     */
    public static function unreadableSettings(): array
    {
        return [
            'the database cannot be used' => [
                fn (self $test) => $test->breakStatsDatabase(),
                'Could not read the user settings: Database connection [stats] not configured.',
            ],
            'it holds something that is not a choice' => [
                fn (self $test) => DB::connection(Usage::CONNECTION)->table('user_settings')->update(['personal_memory_in_calls' => 'whenever']),
                'The user settings hold a value that is not a choice.',
            ],
        ];
    }

    public function testAloneWithTheBotItIsUsedEvenWhenTheSettingCannotBeRead(): void
    {
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->privacy('555', UserSettings::AFTER_SHARE);
        DB::connection(Usage::CONNECTION)->table('user_settings')->update(['personal_memory_in_calls' => 'whenever']);

        $this->ask($vc, '555', self::QUESTION);

        $this->assertStringContainsString('Bananas', $this->claudeCalls()[0]['prompt']);
        $this->assertContains('The user settings hold a value that is not a choice.', $this->loggedProblems());
        await($session->stop());
    }

    public function testItIsLeftOutWhenWhoIsInTheCallIsNotKnown(): void
    {
        // The voice states don't show the bot in its channel, so nobody can say Alice is alone.
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->privacy('555', UserSettings::AFTER_SHARE);

        $this->ask($vc, '555', self::QUESTION);

        $this->assertStringNotContainsString('Bananas', $this->claudeCalls()[0]['prompt']);
        await($session->stop());
    }

    public function testSomeoneWhoOptedOutOfBeingRecordedIsStillSomeoneElseInTheCall(): void
    {
        $this->inCall('555', '777');
        (new OptOuts())->add('777');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->privacy('555', UserSettings::AFTER_SHARE);

        $this->ask($vc, '555', self::QUESTION);

        // Carol is not recorded or answered, but she hears the call: Alice isn't alone.
        $this->assertStringNotContainsString('Bananas', $this->claudeCalls()[0]['prompt']);
        await($session->stop());
    }

    public function testSomeoneJoiningWhileTheQuestionWaitsForItsTurnCounts(): void
    {
        $this->inCall('555');
        $this->setProcessEnv(['FAKE_CLAUDE_PAUSE' => '10']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->privacy('555', UserSettings::AFTER_SHARE);

        // The first question is being answered, and the second one, asked while Alice was alone, waits behind it.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => self::QUESTION]);
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->claudeCalls() !== [], 'Claude to be asked');
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->logged('Utterance ended')) === 2, 'the second question to end');
        $this->joins('666');
        touch($this->claudeResume);
        $this->waitUntil(fn () => count($this->claudeCalls()) === 2, 'Claude to be asked again');
        $this->runFor(0.5);

        // It was asked alone, and answered with the memory the first one had, but the second finds Bob there.
        $this->assertStringContainsString('Bananas', $this->claudeCalls()[0]['prompt']);
        $this->assertStringNotContainsString('Bananas', $this->claudeCalls()[1]['prompt']);
        await($session->stop());
    }

    public function testSomeoneLeavingWhileTheQuestionWaitsForItsTurnDoesNotMakeThemAlone(): void
    {
        $this->inCall('555', '666');
        $this->setProcessEnv(['FAKE_CLAUDE_PAUSE' => '10']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->privacy('555', UserSettings::AFTER_SHARE);

        // The second question is asked with Bob in the call, and waits behind the first one.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => self::QUESTION]);
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->claudeCalls() !== [], 'Claude to be asked');
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->logged('Utterance ended')) === 2, 'the second question to end');
        $this->leaves('666');
        touch($this->claudeResume);
        $this->waitUntil(fn () => count($this->claudeCalls()) === 2, 'Claude to be asked again');

        // Bob heard the question, and was there when it was asked: leaving before it is answered doesn't make it private.
        $this->assertStringNotContainsString('Bananas', $this->claudeCalls()[1]['prompt']);
        await($session->stop());
    }

    public function testAnAnswerBeingWrittenIsDroppedWhenSomeoneJoinsACallItWasMadeForAloneSomeoneIn(): void
    {
        $this->inCall('555');
        $session = $this->startWithTwoSentences($vc, $channel);
        $this->privacy('555', UserSettings::AFTER_SHARE);

        $this->askAndPause($vc, '555');
        // Bob joins once the first sentence was spoken, while Claude is still writing the rest, which may quote her memory.
        $this->joins('666');
        $this->finishTheAnswer();

        $this->assertDropped($session, 'someone joined the call');
    }

    public function testAnAnswerBeingWrittenIsDroppedWhenTheMemoryItNeededSharingForIsTakenBack(): void
    {
        $this->inCall('555', '666');
        $session = $this->startWithTwoSentences($vc, $channel);
        $this->privacy('555', UserSettings::AFTER_SHARE);
        $this->share('555', $channel);

        $this->askAndPause($vc, '555');
        $this->unshare('555');
        $this->finishTheAnswer();

        // Her own memory is in the answer because she shared it: taking it back, the answer may no longer quote it.
        $this->assertDropped($session, 'a shared memory was taken back');
    }

    /**
     * @param callable(self, Channel): void $before What happens before the question, which doesn't make the answer depend on being alone or sharing.
     */
    #[DataProvider('answersThatDoNotDependOnIt')]
    public function testAnAnswerIsKeptWhenNothingItWasMadeForChangedAndSomeoneJoins(callable $before): void
    {
        $this->inCall('555');
        $session = $this->startWithTwoSentences($vc, $channel);
        $before($this, $channel);

        $this->askAndPause($vc, '555');
        $this->joins('666');
        $this->finishTheAnswer();
        $this->waitUntil(fn () => count($this->played) === 2, 'the second sentence to be spoken');
        $this->waitUntil(fn () => $this->usage()['answers'] === 1, 'the answer to be posted');

        $this->assertCount(2, $this->played);
        $this->assertSame(['> **Alice:** ' . self::QUESTION . "\nIt is a quarter past four. Time for a cup of tea."], array_values(array_filter($this->sent, fn (string $message) => str_starts_with($message, '> '))));
        $this->assertSame([], $this->logged('Not answering'));
        $this->assertSame(1, $this->usage()['answers']);
    }

    /**
     * @return array<string, array{callable(self, Channel): void}>
     */
    public static function answersThatDoNotDependOnIt(): array
    {
        return [
            'the default setting' => [fn (self $test, Channel $channel) => null],
            'they shared it' => [function (self $test, Channel $channel) {
                $test->privacy('555', UserSettings::AFTER_SHARE);
                $test->share('555', $channel);
            }],
        ];
    }

    public function testAnAnswerThatLeftTheMemoryOutIsNeverDropped(): void
    {
        // Bob is there the whole time: Alice's memory never gets into the question. Neither does a memory of the
        // two of them, which an answer made from is cut off for when Carol joins: see VoiceMemoryTest.
        $this->memory()->forget(['555', '666']);
        $this->inCall('555', '666');
        $session = $this->startWithTwoSentences($vc, $channel);
        $this->privacy('555', UserSettings::AFTER_SHARE);

        $this->askAndPause($vc, '555');
        $this->joins('777');
        $this->finishTheAnswer();
        $this->waitUntil(fn () => count($this->played) === 2, 'the second sentence to be spoken');
        $this->waitUntil(fn () => $this->usage()['answers'] === 1, 'the answer to be posted');

        $this->assertStringNotContainsString('Bananas', $this->claudeCalls()[0]['prompt']);
        $this->assertCount(2, $this->played);
        $this->assertSame([], $this->logged('Not answering'));
    }

    private function startWithTwoSentences(?object &$vc, ?Channel &$channel): VoiceSession
    {
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past four. ', 'Time for a cup of tea.'),
            'FAKE_CLAUDE_PAUSE' => '10',
        ]);

        return VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
    }

    /**
     * Someone asks, and Claude has written the first sentence, which is spoken.
     */
    private function askAndPause(object $vc, string $userId): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => self::QUESTION]);
        $this->speak($vc, ssrc: (int) $userId, userId: $userId, seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');
    }

    private function finishTheAnswer(): void
    {
        touch($this->claudeResume);
        $this->waitUntil(fn () => $this->logged('Claude answered') !== [], 'Claude to finish');
        $this->runFor(0.5);
    }

    private function assertDropped(VoiceSession $session, string $reason): void
    {
        // What is left isn't spoken, and the answer, which may quote the memory, is neither posted nor kept.
        $this->assertCount(1, $this->played);
        $this->assertCount(1, glob("{$session->directory}/claude-*"), 'The rest isn\'t even synthesized.');
        $this->assertSame([], array_values(array_filter($this->sent, fn (string $message) => str_starts_with($message, '> '))));
        $this->assertStringNotContainsString('Claude:', $this->transcript($session));
        $this->assertSame([['user' => '555', 'reason' => $reason]], array_map(fn (array $context) => array_slice($context, 2), $this->logged('Not answering')));
        $this->assertSame(0, $this->usage()['answers']);
        $this->assertSame([], $this->loggedProblems());
    }

    private function privacy(string $userId, string $choice): void
    {
        (new PrivacyCommand($this->discord))->handle($this->interaction(null, userId: $userId, choices: ['personal_memory_in_calls' => $choice]));
    }

    public function share(string $userId, Channel $channel): void
    {
        (new ShareCommand($this->discord))->handle($this->interaction($channel, userId: $userId));
    }

    public function unshare(string $userId): void
    {
        (new UnshareCommand($this->discord))->handle($this->interaction($this->voiceChannel(), userId: $userId));
    }
}
