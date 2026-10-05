<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Commands\Global\ShareCommand;
use App\Commands\Global\UnshareCommand;
use App\Privacy\OptOuts;
use App\Voice\VoiceSession;
use Discord\Parts\Channel\Channel;
use PHPUnit\Framework\Attributes\DataProvider;

use function React\Async\await;

/**
 * /share and /unshare: someone in the call lets the bot use their personal memory for everyone in it.
 * Alice is 555, Bob 666 and Carol 777; the bot is 999.
 */
final class ShareCommandTest extends CommandTestCase
{
    private const string ALICE = '- Is building a game called Bananas, and wants it out by spring.';

    private const string BOB = '- Is learning to sail, and wants the game to be multiplayer.';

    private const string TRIP = '- Alice and Bob plan a trip to Lisbon.';

    private const string QUESTION = 'Hey Claude, what are we missing from each other\'s point of view?';

    private const string NO_CALL = 'Sharing your memory only works in a call I am recording: join its voice channel and use /share there.';

    private const string SHARED = 'Your memory is shared with this call: I may use it to answer anyone here, and to compare people\'s points of view.'
        . ' It stops when the call ends, or when you use /unshare. A notice goes to the call\'s text channel.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT_MEMORY' => $this->claudeSays('- Updated by Claude.')]);
    }

    public function testAfterSharingEveryoneElsesQuestionsIncludeTheMemory(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->memory()->save('666', self::BOB);
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '666', self::QUESTION);

        // Without /share, Bob only gets his own memory and the one of the group: Alice's stays hers.
        $before = $this->claudeCalls()[0]['prompt'];
        $this->assertSame(
            "What you remember about Bob:\n\n" . self::BOB . "\n\n"
            . "What you remember about Alice and Bob together:\n\n" . self::TRIP . "\n\n"
            . "Transcript of the voice call so far:\n\nBob: " . self::QUESTION . "\n\nBob is talking to you. Reply to their last message.",
            $before,
        );
        $this->assertStringNotContainsString('Bananas', $before);

        $this->share('555', $channel);

        // Only Alice sees the reply. Everyone in the call sees the notice, in the call's text channel.
        $this->assertSame([['content' => self::SHARED, 'ephemeral' => true]], $this->responses);
        $this->assertSame(['> **Bob:** ' . self::QUESTION . "\nIt is a quarter past four.", 'Alice shared their memory with this call.'], $this->sent);

        $this->ask($vc, '666', self::QUESTION);

        // Bob's own memory, the group's, and then Alice's, labeled with her name.
        $after = $this->claudeCalls()[1]['prompt'];
        $this->assertSame(
            "What you remember about Bob:\n\n" . self::BOB . "\n\n"
            . "What you remember about Alice and Bob together:\n\n" . self::TRIP . "\n\n"
            . "What you remember about Alice, who shared their memory with this call:\n\n" . self::ALICE . "\n\n"
            . "Transcript of the voice call so far:\n\nBob: " . self::QUESTION . "\nClaude: It is a quarter past four.\nBob: " . self::QUESTION . "\n\nBob is talking to you. Reply to their last message.",
            $this->untimed($after),
        );

        // Claude is told whose memory is whose, that a shared one may be used for anyone, and what it can do with them.
        $system = $this->claudeCalls()[1]['system'];
        $this->assertStringContainsString('which you only get when they shared them with the call: such a memory is labeled with their name, and you may use it for anyone in the call', $system);
        $this->assertStringContainsString('When you are asked, compare what each person knows or wants, and point out what they might be missing from each other\'s point of view', $system);

        await($session->stop());

        // Sharing is only for the call's questions: the personal memories are not updated from it, only the group's is.
        $this->assertSame(self::ALICE, $this->memory()->read('555'));
        $this->assertSame(self::BOB, $this->memory()->read('666'));
        $this->assertSame('- Updated by Claude.', $this->memory()->read(['555', '666']));
        $this->assertSame(
            [['guild' => self::GUILD_ID, 'session' => $session->id, 'user' => '555']],
            $this->logged('Shared memory'),
        );
        $this->assertLogsNeverMention('Bananas', 'multiplayer', 'Lisbon', 'what are we missing');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testItWorksWhileSomeoneWhoOptedOutIsInTheCall(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->memory()->save(['555', '666', '777'], '- The three of them run a chess club.');
        $this->inCall('555', '666', '777');
        (new OptOuts())->add('777');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->share('555', $channel);

        $this->ask($vc, '666', self::QUESTION);

        // No group memory is used while Carol, who opted out, is there, but Alice chose to share hers.
        $this->assertSame(
            "What you remember about Alice, who shared their memory with this call:\n\n" . self::ALICE . "\n\n"
            . "Transcript of the voice call so far:\n\nBob: " . self::QUESTION . "\n\nBob is talking to you. Reply to their last message.",
            $this->claudeCalls()[0]['prompt'],
        );
        await($session->stop());
    }

    public function testItWorksWithoutAGroupMemoryToo(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->share('555', $channel);

        $this->ask($vc, '666', self::QUESTION);

        // Bob has no memory yet, and the call has none as a group.
        $this->assertSame(
            "What you remember about Alice, who shared their memory with this call:\n\n" . self::ALICE . "\n\n"
            . "Transcript of the voice call so far:\n\nBob: " . self::QUESTION . "\n\nBob is talking to you. Reply to their last message.",
            $this->claudeCalls()[0]['prompt'],
        );
        await($session->stop());
    }

    public function testTheMemoryOfSomeoneWhoSharedIsNotRepeatedWhenTheyAsk(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->share('555', $channel);

        $this->ask($vc, '555', self::QUESTION);

        $prompt = $this->claudeCalls()[0]['prompt'];
        $this->assertSame(1, substr_count($prompt, 'Bananas'));
        $this->assertStringNotContainsString('who shared', $prompt);
        await($session->stop());
    }

    public function testSharingWorksWhateverTheCallIsLike(): void
    {
        $this->memory()->save('555', self::ALICE);
        // Alone with the bot: the memory is hers either way, and nobody else is there to use it.
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->share('555', $channel);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->assertSame(1, substr_count($this->claudeCalls()[0]['prompt'], 'Bananas'));
        await($session->stop());
    }

    public function testUnshareTakesTheMemoryBack(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->share('555', $channel);
        $this->ask($vc, '666', self::QUESTION);
        $this->assertStringContainsString('Bananas', $this->claudeCalls()[0]['prompt']);

        $this->unshare('555');

        $this->assertSame(
            [
                ['content' => self::SHARED, 'ephemeral' => true],
                ['content' => 'Stopped sharing your memory with the call. A notice goes to the call\'s text channel.', 'ephemeral' => true],
            ],
            $this->responses,
        );
        $this->assertSame('Alice stopped sharing their memory with this call.', end($this->sent));

        $this->ask($vc, '666', self::QUESTION);

        $this->assertStringNotContainsString('Bananas', $this->claudeCalls()[1]['prompt']);
        $this->assertSame(self::ALICE, $this->memory()->read('555'), 'Taking it back leaves the memory alone.');
        $this->assertSame(
            [['guild' => self::GUILD_ID, 'session' => $session->id, 'user' => '555']],
            $this->logged('Stopped sharing memory'),
        );

        // Sharing again is a new choice, and is announced again.
        $this->share('555', $channel);
        $this->assertSame('Alice shared their memory with this call.', end($this->sent));
        $this->assertCount(2, $this->logged('Shared memory'));
        $this->assertSame([], $this->loggedProblems());
        await($session->stop());
    }

    #[DataProvider('withdrawals')]
    public function testAnAnswerBeingWrittenIsDroppedWhenSomeoneTakesTheirMemoryBack(callable $withdraw): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555', '666');
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past four. ', 'Time for a cup of tea.'),
            'FAKE_CLAUDE_PAUSE' => '10',
        ]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->share('555', $channel);

        // Alice withdraws once the first sentence of Bob's answer was spoken, while Claude is still writing the rest.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => self::QUESTION]);
        $this->speak($vc, ssrc: 666, userId: '666', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');
        $withdraw($this, $channel);
        touch($this->claudeResume);
        $this->waitUntil(fn () => $this->logged('Claude answered') !== [], 'Claude to finish');
        $this->runFor(0.5);

        // What is left isn't spoken, and the answer, which may quote her memory, is neither posted nor kept.
        $this->assertCount(1, $this->played);
        $this->assertCount(1, glob("{$session->directory}/claude-*"), 'The rest isn\'t even synthesized.');
        $this->assertSame([], array_values(array_filter($this->sent, fn (string $message) => str_starts_with($message, '> '))));
        $this->assertStringNotContainsString('Claude:', $this->transcript($session));
        $this->assertSame([['user' => '666', 'reason' => 'a shared memory was taken back']], array_map(fn (array $context) => array_slice($context, 2), $this->logged('Not answering')));
        $this->assertSame(0, $this->usage()['answers']);
        $this->assertSame([], $this->loggedProblems());
    }

    /**
     * @return array<string, array{callable(self, Channel): void}>
     */
    public static function withdrawals(): array
    {
        return [
            '/unshare' => [fn (self $test, Channel $channel) => $test->unshare('555')],
            '/optout' => [fn (self $test, Channel $channel) => VoiceSession::optOut('555')],
        ];
    }

    public function testAnAnswerIsKeptWhenSomeoneWhoseMemoryItIsNotMadeFromTakesTheirsBack(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Alice shares after Bob's question was asked: the answer isn't made from her memory, so her taking it back leaves it alone.
        $this->setProcessEnv(['FAKE_CLAUDE_PAUSE' => '10', 'FAKE_WHISPER_OUTPUT' => self::QUESTION]);
        $this->speak($vc, ssrc: 666, userId: '666', seconds: 1.0);
        $this->waitUntil(fn () => $this->claudeCalls() !== [], 'Claude to be asked');
        $this->share('555', $channel);
        $this->unshare('555');
        touch($this->claudeResume);
        $this->waitUntil(fn () => count($this->sent) === 3, 'the answer');

        $this->assertSame('> **Bob:** ' . self::QUESTION . "\nIt is a quarter past four.", $this->sent[2]);
        await($session->stop());
    }

    public function testSharingEndsWithTheCall(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555', '666');
        $first = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->share('555', $channel);
        await($first->stop());

        // The call is over: there is nothing left to take back.
        $this->unshare('555');
        $this->assertSame('You are not sharing your memory with a call I am recording.', end($this->responses)['content']);

        // The next call starts without it. Calls are named after the second they started in.
        $this->runFor(1.1);
        $second = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '666', self::QUESTION);

        $calls = $this->claudeCalls();
        $this->assertStringNotContainsString('Bananas', $calls[array_key_last($calls)]['prompt']);
        await($second->stop());
    }

    public function testUnshareWorksFromADirectMessage(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->share('555', $channel);

        (new UnshareCommand($this->discord))->handle($this->interaction(null, guildId: null, userId: '555'));
        $this->ask($vc, '666', self::QUESTION);

        $this->assertSame('Stopped sharing your memory with the call. A notice goes to the call\'s text channel.', end($this->responses)['content']);
        $this->assertSame('Alice stopped sharing their memory with this call.', $this->sent[1]);
        $this->assertStringNotContainsString('Bananas', $this->claudeCalls()[0]['prompt']);
        await($session->stop());
    }

    public function testUnshareTakesTheMemoryBackFromEveryCallOfTheUser(): void
    {
        $this->memory()->save('555', self::ALICE);
        $first = VoiceSession::start($this->voiceClient($firstChannel = $this->voiceChannel()), $firstChannel, $this->discord);
        $second = VoiceSession::start($this->voiceClient($secondChannel = $this->voiceChannel('201', '101')), $secondChannel, $this->discord);

        // Alice shares with a call in each of two servers.
        $this->share('555', $firstChannel);
        (new ShareCommand($this->discord))->handle($this->interaction($secondChannel, guildId: '101', userId: '555'));
        (new UnshareCommand($this->discord))->handle($this->interaction(null, guildId: null, userId: '555'));

        $this->assertSame('Stopped sharing your memory with the call. A notice goes to the call\'s text channel.', end($this->responses)['content']);
        $this->assertSame([$first->id, $second->id], array_column($this->logged('Stopped sharing memory'), 'session'));
        $this->assertCount(2, array_filter($this->sent, fn (string $message) => str_contains($message, 'stopped sharing')));
        await($first->stop());
        await($second->stop());
    }

    public function testUnshareTakesTheMemoryBackFromTheCallItWasSharedWithOnly(): void
    {
        $this->memory()->save('555', self::ALICE);
        $first = VoiceSession::start($this->voiceClient($firstChannel = $this->voiceChannel()), $firstChannel, $this->discord);
        $second = VoiceSession::start($this->voiceClient($secondChannel = $this->voiceChannel('201', '101')), $secondChannel, $this->discord);
        $this->share('555', $firstChannel);

        // The other call, which comes last, has nothing to take back, and that doesn't change what the first one had.
        (new UnshareCommand($this->discord))->handle($this->interaction(null, guildId: null, userId: '555'));

        $this->assertSame('Stopped sharing your memory with the call. A notice goes to the call\'s text channel.', end($this->responses)['content']);
        $this->assertSame([$first->id], array_column($this->logged('Stopped sharing memory'), 'session'));
        await($first->stop());
        await($second->stop());
    }

    public function testNothingIsLeftToTakeBackFromACallThatStopped(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->share('555', $channel);
        $this->ask($vc, '666', self::QUESTION);

        // The call is over, but its summary is still being written.
        $ended = $session->stop();
        $this->unshare('555');
        await($ended);

        $this->assertSame('You are not sharing your memory with a call I am recording.', end($this->responses)['content']);
        $this->assertSame([], $this->logged('Stopped sharing memory'));
        $this->assertSame(
            ['Alice shared their memory with this call.'],
            array_values(array_filter($this->sent, fn (string $message) => str_contains($message, 'memory with this call'))),
        );
    }

    public function testSentencesWaitingForPiperAreNotSynthesizedOnceSomeoneTakesTheirMemoryBack(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555', '666');
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past four. ', 'Time for a cup of tea. ', 'Then a nap.'),
            'FAKE_PIPER_DELAY' => '0.5',
            'FAKE_WHISPER_OUTPUT' => self::QUESTION,
        ]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->share('555', $channel);

        // Claude is done, and Piper is still busy with the first sentence, when Alice takes her memory back.
        $this->speak($vc, ssrc: 666, userId: '666', seconds: 1.0);
        $this->waitUntil(fn () => $this->logged('Claude answered') !== [], 'Claude to finish');
        $this->unshare('555');
        $this->runFor(2.0);

        $this->assertSame([], $this->played);
        $this->assertCount(1, glob("{$session->directory}/claude-*"), 'Only the sentence Piper was already working on.');
        await($session->stop());
    }

    public function testSharingSurvivesTheSharerLeavingAndComingBack(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->share('555', $channel);

        $this->leaves('555');
        $this->ask($vc, '666', self::QUESTION);
        $this->assertStringContainsString('Bananas', $this->claudeCalls()[0]['prompt'], 'It lasts until the call ends.');

        // Someone who left can still take it back, without being in a voice channel, or even in the server.
        (new UnshareCommand($this->discord))->handle($this->interaction(null, userId: '555'));

        $this->assertSame('Stopped sharing your memory with the call. A notice goes to the call\'s text channel.', end($this->responses)['content']);
        $this->joins('555');
        $this->ask($vc, '666', self::QUESTION);
        $this->assertStringNotContainsString('Bananas', $this->claudeCalls()[1]['prompt']);
        await($session->stop());
    }

    public function testAPromptHoldsTheMemoriesOfTheFiveWhoSharedMostRecently(): void
    {
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        foreach (['1001', '1002', '1003', '1004', '1005', '1006'] as $sharer) {
            $this->memory()->save($sharer, "- Memory of {$sharer}.");
            $this->share($sharer, $channel);
        }

        $this->ask($vc, '666', self::QUESTION);

        // The first to share is left out, and the rest keep the order they shared in.
        $prompt = $this->claudeCalls()[0]['prompt'];
        $this->assertSame(5, substr_count($prompt, 'who shared their memory with this call'));
        $this->assertStringNotContainsString('1001', $prompt);
        preg_match_all('/^- Memory of (\d+)\.$/m', $prompt, $shared);
        $this->assertSame(['1002', '1003', '1004', '1005', '1006'], $shared[1]);

        // Whoever shares again, after taking it back, is the most recent: the one who shared second now is the one left out.
        $this->unshare('1001');
        $this->share('1001', $channel);
        $this->ask($vc, '666', self::QUESTION);

        $prompt = $this->claudeCalls()[1]['prompt'];
        $this->assertStringNotContainsString('Memory of 1002', $prompt);
        preg_match_all('/^- Memory of (\d+)\.$/m', $prompt, $shared);
        $this->assertSame(['1003', '1004', '1005', '1006', '1001'], $shared[1]);
        await($session->stop());
    }

    public function testTheAskerCountsAsOneOfTheSharedMemoriesOfAPrompt(): void
    {
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        foreach (['1001', '1002', '1003', '1004', '1005', '1006'] as $sharer) {
            $this->memory()->save($sharer, "- Memory of {$sharer}.");
            $this->share($sharer, $channel);
        }

        $this->ask($vc, '1006', self::QUESTION);

        // The five who shared last are 1002 to 1006. The asker's memory is the first one in the prompt, as their own, and isn't repeated.
        $prompt = $this->claudeCalls()[0]['prompt'];
        preg_match_all('/^- Memory of (\d+)\.$/m', $prompt, $memories);
        $this->assertSame(['1006', '1002', '1003', '1004', '1005'], $memories[1]);
        $this->assertSame(4, substr_count($prompt, 'who shared their memory with this call'));
        await($session->stop());
    }

    public function testAMemoryThatWasForgottenIsNoLongerUsed(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->share('555', $channel);

        // /forget deletes the file; the next question reads what is there.
        $this->memory()->forget('555');
        $this->ask($vc, '666', self::QUESTION);

        $this->assertStringNotContainsString('who shared', $this->claudeCalls()[0]['prompt']);
        await($session->stop());
    }

    public function testOptingOutOfBeingRecordedStopsSharing(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->share('555', $channel);

        VoiceSession::optOut('555');
        $this->ask($vc, '666', self::QUESTION);

        $this->assertStringNotContainsString('Bananas', $this->claudeCalls()[0]['prompt']);
        // The call is told when someone shares, not when opting out ends it: the memory is just no longer used.
        $this->assertSame(['Alice shared their memory with this call.', '> **Bob:** ' . self::QUESTION . "\nIt is a quarter past four."], $this->sent);
        // There is nothing left to take back.
        $this->unshare('555');
        $this->assertSame('You are not sharing your memory with a call I am recording.', end($this->responses)['content']);
        await($session->stop());
    }

    public function testWhoeverOptedOutOfBeingRecordedCannotShare(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555', '666');
        (new OptOuts())->add('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->share('555', $channel);
        $this->ask($vc, '666', self::QUESTION);

        $refusal = 'You opted out of being recorded, so I don\'t use your memory in calls either. Use /optin first, then /share.';
        $this->assertSame([['content' => $refusal, 'ephemeral' => true]], $this->responses);
        $this->assertStringNotContainsString('Bananas', $this->claudeCalls()[0]['prompt']);
        $this->assertSame([], $this->logged('Shared memory'));

        // Opting back in, in the call, makes it their choice again.
        VoiceSession::optIn('555');
        $this->share('555', $channel);

        $this->assertSame(self::SHARED, end($this->responses)['content']);
        $this->assertSame(['> **Bob:** ' . self::QUESTION . "\nIt is a quarter past four.", 'Alice shared their memory with this call.'], $this->sent);
        await($session->stop());
    }

    public function testRefusesOutsideACallTheBotIsRecording(): void
    {
        $this->memory()->save('555', self::ALICE);

        // The bot isn't recording anything.
        $this->share('555', $this->voiceChannel());

        $this->assertSame([['content' => self::NO_CALL, 'ephemeral' => true]], $this->responses);
        $this->assertSame([], $this->logged('Shared memory'));
    }

    public function testRefusesSomeoneWhoIsNotInTheCall(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->memory()->save('666', self::BOB);
        $this->inCall('555');
        $session = VoiceSession::start($this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Bob is in no voice channel, Carol in another one, and Alice is in a direct message with the bot.
        (new ShareCommand($this->discord))->handle($this->interaction(null, userId: '666'));
        (new ShareCommand($this->discord))->handle($this->interaction($this->voiceChannel('300'), userId: '777'));
        (new ShareCommand($this->discord))->handle($this->interaction(null, guildId: null));

        $this->assertSame(array_fill(0, 3, ['content' => self::NO_CALL, 'ephemeral' => true]), $this->responses);
        $this->assertSame([], $this->sent, 'Nothing is announced.');
        $this->assertSame([], $this->logged('Shared memory'));
        await($session->stop());
    }

    public function testSaysSoWhenThereIsNothingToShare(): void
    {
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->share('555', $channel);
        $this->ask($vc, '666', self::QUESTION);

        $this->assertSame(
            [['content' => "I don't remember anything about you yet, so there is nothing to share. Send me a direct message to chat with me.", 'ephemeral' => true]],
            $this->responses,
        );
        $this->assertSame(['> **Bob:** ' . self::QUESTION . "\nIt is a quarter past four."], $this->sent, 'Nothing is announced.');
        $this->assertStringNotContainsString('who shared', $this->claudeCalls()[0]['prompt']);
        $this->assertSame([], $this->logged('Shared memory'));
        await($session->stop());
    }

    public function testSaysSoWhenAlreadySharing(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555');
        $session = VoiceSession::start($this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->share('555', $channel);
        $this->share('555', $channel);

        $this->assertSame(
            [
                ['content' => self::SHARED, 'ephemeral' => true],
                ['content' => 'You are already sharing your memory with this call. Use /unshare to take it back.', 'ephemeral' => true],
            ],
            $this->responses,
        );
        $this->assertSame(['Alice shared their memory with this call.'], $this->sent, 'It is only announced once.');
        $this->assertCount(1, $this->logged('Shared memory'));
        await($session->stop());
    }

    public function testUnshareSaysSoWhenThereIsNothingToTakeBack(): void
    {
        // No call at all, in a server and in a direct message.
        $this->unshare('555');
        (new UnshareCommand($this->discord))->handle($this->interaction(null, guildId: null));

        $this->inCall('555');
        $session = VoiceSession::start($this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        // A call where someone else shares.
        $this->memory()->save('666', self::BOB);
        $this->share('666', $channel);
        $this->unshare('555');

        $this->assertSame(
            array_fill(0, 3, 'You are not sharing your memory with a call I am recording.'),
            array_values(array_filter(array_column($this->responses, 'content'), fn (string $reply) => str_starts_with($reply, 'You are not'))),
        );
        $this->assertSame([], $this->logged('Stopped sharing memory'));
        $this->assertSame(['Bob shared their memory with this call.'], $this->sent);
        await($session->stop());
    }

    private function share(string $userId, Channel $channel): void
    {
        (new ShareCommand($this->discord))->handle($this->interaction($channel, userId: $userId));
    }

    public function unshare(string $userId): void
    {
        (new UnshareCommand($this->discord))->handle($this->interaction($this->voiceChannel(), userId: $userId));
    }
}
