<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Assistant\MemoryGroup;
use App\Privacy\OptOuts;
use App\Voice\VoiceSession;
use PHPUnit\Framework\Attributes\TestWith;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Async\await;
use function React\Promise\all;

/**
 * What the bot remembers of calls: a personal memory for someone alone with the bot, and a group
 * memory for each group of people in the voice channel. Alice is 555, Bob 666 and Carol 777; the bot is 999.
 */
final class VoiceMemoryTest extends VoiceTestCase
{
    private const string ALICE = '- Is building a game called Bananas.';

    private const string BOB = '- Is learning to sail.';

    private const string TRIP = '- Alice and Bob plan a trip to Lisbon.';

    private const string CLUB = '- The three of them run a chess club.';

    private const string NEW_MEMORY = '- Updated by Claude.';

    private const string ANSWER = 'It is a quarter past four.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT_MEMORY' => $this->claudeSays(self::NEW_MEMORY)]);
    }

    public function testAloneWithTheBotTheCallUsesAndUpdatesThePersonalMemory(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        // Claude gets Alice's personal memory, the one of her direct messages, before the call so far.
        $answering = $this->claudeCalls()[0];
        $this->assertSame(
            "What you remember about Alice:\n\n" . self::ALICE . "\n\n"
            . "Transcript of the voice call so far:\n\nAlice: Hey Claude, what time is it?\n\n" . $this->asking('Alice', 'Hey Claude, what time is it?'),
            $this->untimed($answering['prompt']),
        );
        // It is told whose memory is whose, and that the whole call hears its answer.
        $this->assertStringContainsString('what you remember about the person you are answering, and what you remember about everyone in the call together', $answering['system']);
        $this->assertStringContainsString('other people in the call have memories of their own, which you only get when they shared them with the call', $answering['system']);
        $this->assertStringContainsString('Everyone in the call hears your answer, so only bring up from a memory what the question needs', $answering['system']);
        $this->assertSame(self::ALICE, $this->memory()->read('555'), 'Nothing is remembered while the call goes on.');

        await($session->stop());

        // After the summary, one request updates her memory from what was said in the call.
        $calls = $this->claudeCalls();
        $this->assertCount(3, $calls);
        $this->assertStringStartsWith('You summarize Discord voice calls', $calls[1]['system']);
        $this->assertSame(
            "The current memory:\n\n" . self::ALICE . "\n\n"
            . "What was said since it was last updated:\n\nAlice: Hey Claude, what time is it?\nClaude: " . self::ANSWER . "\n\nReply with the new memory.",
            $this->untimed($calls[2]['prompt']),
        );
        $this->assertStringStartsWith("You keep a Discord bot's memory of one person", $calls[2]['system']);
        $this->assertSame(self::NEW_MEMORY, $this->memory()->read('555'));
        $this->assertDirectoryDoesNotExist("{$this->memories}/groups");

        // The log says how many people it belongs to and how long it is, and which call it was.
        $this->assertSame(
            [['guild' => self::GUILD_ID, 'session' => $session->id, 'people' => 1, 'characters' => mb_strlen(self::NEW_MEMORY)]],
            $this->logged('Updated memory'),
        );
        $this->assertLogsNeverMention('Bananas', 'Updated by Claude', 'quarter past four');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testWithOthersTheCallUsesAndUpdatesTheGroupMemory(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->memory()->save('666', self::BOB);
        $this->memory()->save(['555', '666'], self::TRIP);
        // Bob never says a word: the group is everyone in the channel, not only who spoke.
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        // The group's memory, and the asker's personal one, labeled with names: not Bob's personal memory.
        $answering = $this->claudeCalls()[0]['prompt'];
        $this->assertSame(
            "What you remember about Alice:\n\n" . self::ALICE . "\n\n"
            . "What you remember about Alice and Bob together:\n\n" . self::TRIP . "\n\n"
            . "Transcript of the voice call so far:\n\nAlice: Hey Claude, what time is it?\n\n" . $this->asking('Alice', 'Hey Claude, what time is it?'),
            $this->untimed($answering),
        );
        $this->assertStringNotContainsString('sail', $answering);

        await($session->stop());

        // Only the group's memory is updated, from the call's transcript. The personal ones are never updated from a call with others.
        $calls = $this->claudeCalls();
        $this->assertCount(3, $calls);
        $this->assertSame(
            "The current memory:\n\n" . self::TRIP . "\n\n"
            . "What was said since it was last updated:\n\nAlice: Hey Claude, what time is it?\n\nReply with the new memory.",
            $this->untimed($calls[2]['prompt']),
            'What Claude answered is left out: it may quote a memory that isn\'t the group\'s.',
        );
        $this->assertStringStartsWith("You keep a Discord bot's memory of a group of people", $calls[2]['system']);
        $this->assertStringContainsString('The transcript only has what the people said: what the assistant answered them is left out.', $calls[2]['system']);
        $this->assertStringContainsString('Never include passwords, tokens, keys or other secrets', $calls[2]['system']);
        $this->assertStringContainsString('Stay under 4000 characters', $calls[2]['system']);
        $this->assertSame(self::NEW_MEMORY, $this->memory()->read(['666', '555']));
        $this->assertSame(self::ALICE, $this->memory()->read('555'));
        $this->assertSame(self::BOB, $this->memory()->read('666'));

        // The group's memory is at MEMORY_PATH/groups/<the people's IDs, sorted, joined with ->.md, and only the bot's user can read it.
        $this->assertSame(self::NEW_MEMORY . "\n", file_get_contents("{$this->memories}/groups/555-666.md"));
        $this->assertSame('0600', substr(sprintf('%o', fileperms("{$this->memories}/groups/555-666.md")), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms("{$this->memories}/groups")), -4));
        $this->assertSame(['555-666.md'], array_values(array_diff(scandir("{$this->memories}/groups"), ['.', '..'])));

        $this->assertSame([['guild' => self::GUILD_ID, 'session' => $session->id, 'people' => 2, 'characters' => mb_strlen(self::NEW_MEMORY)]], $this->logged('Updated memory'));
        $this->assertLogsNeverMention('Bananas', 'Lisbon', 'Updated by Claude', 'sail');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testTheGroupIsExactlyThePeopleInTheChannel(): void
    {
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->memory()->save(['555', '666', '777'], '- The three of them run a chess club.');
        $this->inCall('555', '666', '777');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $answering = $this->claudeCalls()[0]['prompt'];
        $this->assertStringContainsString("What you remember about Alice, Bob and Carol together:\n\n- The three of them run a chess club.\n\n", $answering);
        $this->assertStringNotContainsString('Lisbon', $answering, 'The memory of two of them is not for a call with three.');

        await($session->stop());

        $this->assertSame(self::NEW_MEMORY, $this->memory()->read(['555', '666', '777']));
        $this->assertSame(self::TRIP, $this->memory()->read(['555', '666']));
        $this->assertSame([3], array_column($this->logged('Updated memory'), 'people'));
    }

    public function testSomeoneJoiningOrLeavingSwitchesTheMemoryAndEachStretchUpdatesItsOwn(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Alone with the bot, then with Bob, then alone again.
        $this->ask($vc, '555', 'Hey Claude, first.');
        $this->joins('666');
        $this->ask($vc, '555', 'Hey Claude, second.');
        $this->leaves('666');
        $this->ask($vc, '555', 'Hey Claude, third.');

        [$first, $second, $third] = array_column($this->claudeCalls(), 'prompt');
        $this->assertStringContainsString("What you remember about Alice:\n\n" . self::ALICE, $first);
        $this->assertStringNotContainsString('Lisbon', $first);
        $this->assertStringContainsString("What you remember about Alice and Bob together:\n\n" . self::TRIP, $second);
        $this->assertStringNotContainsString('Lisbon', $third, 'The next question uses the memory of the new group.');

        await($session->stop());

        // One request for each memory, even for the one the call came back to: Alice's, from the first and the third stretch.
        $calls = $this->claudeCalls();
        $this->assertCount(6, $calls, 'Three answers, the summary, and one update for each of the two memories.');
        $this->assertSame(
            "The current memory:\n\n" . self::ALICE . "\n\n"
            . "What was said since it was last updated:\n\nAlice: Hey Claude, first.\nClaude: " . self::ANSWER
            . "\nAlice: Hey Claude, third.\nClaude: " . self::ANSWER . "\n\nReply with the new memory.",
            $this->untimed($calls[4]['prompt']),
        );
        $this->assertSame(
            "The current memory:\n\n" . self::TRIP . "\n\n"
            . "What was said since it was last updated:\n\nAlice: Hey Claude, second.\n\nReply with the new memory.",
            $this->untimed($calls[5]['prompt']),
        );
        $this->assertSame(self::NEW_MEMORY, $this->memory()->read('555'));
        $this->assertSame(self::NEW_MEMORY, $this->memory()->read(['555', '666']));
        $this->assertSame([1, 2], array_column($this->logged('Updated memory'), 'people'));
    }

    public function testWhatSomeoneSaidBeforeLeavingIsNeverRememberedWithoutThem(): void
    {
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Bob leaves right after he speaks, before what he said is over and transcribed: Alice stays alone with the bot.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'We should quietly move the launch.']);
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $this->leaves('666');
        $this->waitUntil(fn () => $this->transcript($session) !== '', 'the transcript');

        await($session->stop());

        // It goes to the memory of the two of them, not to Alice's personal one.
        $this->assertFileDoesNotExist("{$this->memories}/555.md");
        $this->assertFileDoesNotExist("{$this->memories}/666.md");
        $this->assertSame(self::NEW_MEMORY, $this->memory()->read(['555', '666']));
        $this->assertStringContainsString("Bob: We should quietly move the launch.\n\nReply", $this->untimed($this->claudeCalls()[1]['prompt']));
        $this->assertSame([2], array_column($this->logged('Updated memory'), 'people'));
    }

    public function testDoesNotKnowWhoIsInTheCallUntilTheVoiceStatesShowTheBotInIt(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->memory()->save(['555', '666'], self::TRIP);
        // The cache has Alice and Bob, but not the bot itself in its channel: who else is there can't be trusted.
        $this->voiceStates[] = $this->inVoice('555');
        $this->voiceStates[] = $this->inVoice('666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        // Alice's personal memory is hers whenever she asks. No group memory is used, or updated.
        $this->assertStringStartsWith("What you remember about Alice:\n\n" . self::ALICE . "\n\nTranscript of the voice call so far:", $this->claudeCalls()[0]['prompt']);
        $this->assertStringNotContainsString('Lisbon', $this->claudeCalls()[0]['prompt']);

        await($session->stop());

        $this->assertCount(2, $this->claudeCalls(), 'The answer and the summary: nothing is updated.');
        $this->assertSame(self::ALICE, $this->memory()->read('555'));
        $this->assertSame(self::TRIP, $this->memory()->read(['555', '666']));
        $this->assertSame([], $this->logged('Updated memory'));
    }

    public function testUsesAndUpdatesNoGroupMemoryWhileSomeoneWhoOptedOutIsInTheCall(): void
    {
        (new OptOuts())->add('666');
        $this->memory()->save('555', self::ALICE);
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        // Bob isn't recorded, so he is not asked about, and his group's memory is left out. Alice's own still counts.
        $this->assertStringStartsWith("What you remember about Alice:\n\n" . self::ALICE . "\n\nTranscript of the voice call so far:", $this->claudeCalls()[0]['prompt']);
        $this->assertStringNotContainsString('Lisbon', $this->claudeCalls()[0]['prompt']);

        await($session->stop());

        $this->assertCount(2, $this->claudeCalls(), 'The answer and the summary: nothing is updated.');
        $this->assertSame(self::ALICE, $this->memory()->read('555'));
        $this->assertSame(self::TRIP, $this->memory()->read(['555', '666']));
        $this->assertSame([], $this->logged('Updated memory'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testStopsRememberingWhenSomeoneOptsOutDuringTheCallAndGoesOnWhenTheyOptBackIn(): void
    {
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, first.');
        VoiceSession::optOut('666');
        $this->ask($vc, '555', 'Hey Claude, second.');
        VoiceSession::optIn('666');
        $this->ask($vc, '555', 'Hey Claude, third.');

        [$first, $second, $third] = array_column($this->claudeCalls(), 'prompt');
        $this->assertStringContainsString('Lisbon', $first);
        $this->assertStringNotContainsString('Lisbon', $second, 'Bob opted out: no group memory while he is in the call.');
        $this->assertStringContainsString('Lisbon', $third);

        await($session->stop());

        // What was said while he was opted out is not remembered.
        $update = $this->untimed($this->claudeCalls()[4]['prompt']);
        $this->assertStringContainsString('Alice: Hey Claude, first.', $update);
        $this->assertStringNotContainsString('second', $update);
        $this->assertStringContainsString('Alice: Hey Claude, third.', $update);
        $this->assertSame(self::NEW_MEMORY, $this->memory()->read(['555', '666']));
    }

    public function testUpdatesNoMemoryOfSomeoneWhoOptedOutAfterWhatTheySaidWasTranscribed(): void
    {
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        VoiceSession::optOut('666');

        await($session->stop());

        $this->assertCount(2, $this->claudeCalls(), 'The answer and the summary: nothing is updated.');
        $this->assertSame(self::TRIP, $this->memory()->read(['555', '666']));
        $this->assertSame([], $this->logged('Updated memory'));
    }

    public function testALostUpdateIsLoggedAndTheOthersAreStillMade(): void
    {
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, first.');
        $this->leaves('666');
        $this->ask($vc, '555', 'Hey Claude, second.');
        // The group's memory can't be saved: its folder is a file. It is the first to be updated.
        mkdir($this->memories, 0700, true);
        touch("{$this->memories}/groups");
        $problem = "Could not update the memory: The memory could not be saved in {$this->memories}/groups.";

        await($session->stop());

        $this->assertSame([$problem], $this->loggedProblems());
        $this->assertSame([2], array_column($this->logged($problem), 'people'));
        $this->assertCount(5, $this->claudeCalls(), 'Two answers, the summary, and one update for each of the two memories.');
        $this->assertSame(self::NEW_MEMORY, $this->memory()->read('555'), 'Alice\'s memory was updated after it.');
        $this->assertSame([1], array_column($this->logged('Updated memory'), 'people'));
        $this->assertSame(['555.md', 'groups'], array_values(array_diff(scandir($this->memories), ['.', '..'])), 'Nothing is left behind.');
    }

    public function testForgettingAMemoryDropsWhatWasSaidSinceItWasLastUpdated(): void
    {
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, my PIN is 1234.');
        // /forget with:@Bob, in any order of the IDs.
        VoiceSession::forget(['666', '555']);
        $this->ask($vc, '555', 'Hey Claude, I am learning to sail.');

        await($session->stop());

        $update = $this->untimed($this->claudeCalls()[3]['prompt']);
        $this->assertStringNotContainsString('1234', $update);
        $this->assertStringContainsString('Alice: Hey Claude, I am learning to sail.', $update);
        $this->assertSame(self::NEW_MEMORY, $this->memory()->read(['555', '666']));
    }

    public function testForgettingAPersonalMemoryDropsWhatWasSaidAloneWithTheBot(): void
    {
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, my PIN is 1234.');
        VoiceSession::forget('555');

        await($session->stop());

        $this->assertCount(1, $this->claudeCalls(), 'Only the answer: nothing is left to summarize or remember.');
        $this->assertFileDoesNotExist("{$this->memories}/555.md");
        $this->assertFileDoesNotExist("{$session->directory}/transcript.txt");
        $this->assertSame([], $this->logged('Updated memory'));
    }

    public function testForgettingAMemoryTakesWhatWasSaidOutOfTheTranscriptClaudeIsGiven(): void
    {
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, my PIN is 1234.');
        $this->assertStringContainsString('Alice: Hey Claude, my PIN is 1234.', $this->transcript($session));
        VoiceSession::forget('555');
        $this->ask($vc, '555', 'Hey Claude, I am learning to sail.');

        // What was said, and what Claude answered, before /forget is not in the file, nor in what the next answer is given.
        $transcript = $this->transcript($session);
        $this->assertStringNotContainsString('1234', $transcript);
        $this->assertSame(1, substr_count($transcript, 'Claude: '), 'Only the second answer is left.');
        $this->assertStringContainsString('Alice: Hey Claude, I am learning to sail.', $transcript);
        $this->assertStringNotContainsString('1234', $this->claudeCalls()[1]['prompt']);
        $this->assertStringContainsString('Alice: Hey Claude, I am learning to sail.', $this->claudeCalls()[1]['prompt']);

        await($session->stop());

        // Nor in the summary.
        $this->assertStringNotContainsString('1234', $this->claudeCalls()[2]['prompt']);
    }

    public function testWhatWasSaidBeforeForgettingButTranscribedAfterIsNotRemembered(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Hey Claude, my PIN is 1234.']);
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Alice says it, and uses /forget while whisper is still busy with it.
        $this->speakAndWait($vc, '555');
        VoiceSession::forget('555');
        $this->transcribe();
        $this->waitUntil(fn () => str_contains($this->transcript($session), 'Alice: Hey Claude, my PIN is 1234.'), 'it to be transcribed');
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        // It is still answered, so it is in the transcript Claude is asked with, but it is no part of any memory.
        await($session->stop());
        $this->assertFileDoesNotExist("{$this->memories}/555.md");
        $this->assertSame([], $this->logged('Updated memory'));
    }

    public function testWhatClaudeAnswersWhileAMemoryIsForgottenIsNotRemembered(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555');
        $this->setProcessEnv(['FAKE_CLAUDE_PAUSE' => '10']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->claudeCalls() !== [], 'Claude to be asked');
        // She uses /forget while Claude, who was asked with her memory, is still writing the answer.
        $this->memory()->forget('555');
        VoiceSession::forget('555');
        touch($this->claudeResume);
        $this->waitUntil(fn () => $this->sent !== [], 'the answer to be posted');

        await($session->stop());
        $this->assertFileDoesNotExist("{$this->memories}/555.md");
        $this->assertSame([], $this->logged('Updated memory'));
    }

    public function testForgettingAPersonalMemoryKeepsWhatWasSaidWhileOthersWereThere(): void
    {
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, my PIN is 1234.');

        // It would be remembered in their group's memory, not in Alice's own: /forget without options is about hers.
        VoiceSession::forget('555');
        $this->assertStringContainsString('Alice: Hey Claude, my PIN is 1234.', $this->transcript($session));

        VoiceSession::forget(['555', '666']);
        // What Claude answered with others there was never going to be remembered: it stays.
        $this->assertStringNotContainsString('1234', $this->transcript($session), "The group's memory was forgotten too.");
        $this->assertStringContainsString('Claude: ', $this->transcript($session));
    }

    public function testAMemoryForgottenWhileItIsBeingUpdatedStaysForgotten(): void
    {
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, my PIN is 1234.');

        // Claude is still writing the new memory, for as long as the test says.
        $this->holdMemoryUpdates();
        $ended = $session->stop();
        $this->waitUntil(fn () => $this->memoryUpdates() !== [], 'Claude to be asked for the new memory');
        VoiceSession::forget('555');
        $this->releaseMemoryUpdates();
        await($ended);

        $this->assertFileDoesNotExist("{$this->memories}/555.md");
        $this->assertSame([], $this->logged('Updated memory'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAGroupMemoryIsLeftOutWhenSomeoneJoinsWhileTheQuestionWaitsForItsTurn(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->memory()->save(['555', '666', '777'], self::CLUB);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Alice and Bob both ask something, and Carol joins before either question is transcribed: Bob's waits for Alice's.
        $this->speakAndWait($vc, '555', '666');
        $this->joins('777');
        $this->transcribe();
        $this->waitUntil(fn () => count($this->sent) === 2, 'both answers');

        // Carol hears both answers, and the memory of Alice and Bob isn't hers. The memory of the three
        // of them isn't used either: it is for what is asked with the three of them there.
        [$alice, $bob] = array_column($this->claudeCalls(), 'prompt');
        $this->assertStringNotContainsString('Lisbon', $alice . $bob);
        $this->assertStringNotContainsString('chess', $alice . $bob);
        // The asker's own memory is still theirs to be answered with.
        $this->assertStringStartsWith("What you remember about Alice:\n\n" . self::ALICE . "\n\nTranscript of the voice call so far:", $alice);
        $this->assertStringStartsWith('Transcript of the voice call so far:', $bob);

        await($session->stop());

        // What was said is still kept with who was there when it was said, not when it was transcribed or answered.
        $this->assertSame(self::NEW_MEMORY, $this->memory()->read(['555', '666']));
        $this->assertSame(self::CLUB, $this->memory()->read(['555', '666', '777']), 'Carol was not there when it was said.');
        $this->assertSame([2], array_column($this->logged('Updated memory'), 'people'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAGroupMemoryIsLeftOutWhenSomeoneLeavesWhileTheQuestionWaitsForItsTurn(): void
    {
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->memory()->save(['555', '666', '777'], self::CLUB);
        $this->inCall('555', '666', '777');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Carol leaves after Alice asked, before the question is transcribed.
        $this->speakAndWait($vc, '555');
        $this->leaves('777');
        $this->transcribe();
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        // A group's memory is for a call of exactly its people: not the three's, as Carol left, and not
        // the one of Alice and Bob, as the question was asked with Carol there and is kept with the three.
        $this->assertSame(
            "Transcript of the voice call so far:\n\nAlice: Hey Claude, what time is it?\n\n" . $this->asking('Alice', 'Hey Claude, what time is it?'),
            $this->untimed($this->claudeCalls()[0]['prompt']),
        );

        await($session->stop());

        $this->assertSame(self::NEW_MEMORY, $this->memory()->read(['555', '666', '777']));
        $this->assertSame(self::TRIP, $this->memory()->read(['555', '666']));
    }

    public function testASharedMemoryStaysInAQuestionThatLosesItsGroupMemory(): void
    {
        $this->memory()->save('666', self::BOB);
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        // Bob shared his memory with the call, so with whoever is in it: Carol too, once she joins.
        $session->share('666');

        $this->speakAndWait($vc, '555');
        $this->joins('777');
        $this->transcribe();
        $this->waitUntil(fn () => count($this->sent) === 2, 'the answer');

        $this->assertSame(
            "What you remember about Bob, who shared their memory with this call:\n\n" . self::BOB . "\n\n"
            . "Transcript of the voice call so far:\n\nAlice: Hey Claude, what time is it?\n\n" . $this->asking('Alice', 'Hey Claude, what time is it?'),
            $this->untimed($this->claudeCalls()[0]['prompt']),
        );

        await($session->stop());
    }

    public function testSkipsTheMemoriesStillWaitingWhenTheirSayingIsForgotten(): void
    {
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, first.');
        $this->joins('666');
        $this->ask($vc, '555', 'Hey Claude, second.');

        // Alice's own memory is being updated when the memory of her and Bob is forgotten: nothing is left to update it with.
        $this->holdMemoryUpdates();
        $ended = $session->stop();
        $this->waitUntil(fn () => $this->memoryUpdates() !== [], 'Claude to be asked for the first new memory');
        VoiceSession::forget(['555', '666']);
        $this->releaseMemoryUpdates();
        await($ended);

        $this->assertCount(4, $this->claudeCalls(), 'Two answers, the summary, and the update of Alice\'s memory only.');
        $this->assertSame(self::NEW_MEMORY, $this->memory()->read('555'));
        $this->assertFileDoesNotExist("{$this->memories}/groups/555-666.md");
        $this->assertSame([1], array_column($this->logged('Updated memory'), 'people'));
    }

    public function testSomeoneWhoOptedOutOfBeingRecordedButIsNotInTheCallChangesNothing(): void
    {
        // Opt-outs count in every server: only whoever is in this call matters here.
        (new OptOuts())->add('888');
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        VoiceSession::optOut('777');

        $this->assertStringContainsString('Lisbon', $this->claudeCalls()[0]['prompt']);

        await($session->stop());

        $this->assertCount(3, $this->claudeCalls());
        $this->assertSame(self::NEW_MEMORY, $this->memory()->read(['555', '666']));
    }

    public function testPeopleInAnotherVoiceChannelOfTheServerAreNotInTheCall(): void
    {
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->memory()->save(['555', '666', '777'], '- The three of them run a chess club.');
        $this->inCall('555', '666');
        // Carol is in another channel of the same server: its voice states are all in the cache.
        $this->voiceStates[] = $this->inVoice('777', '300');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->assertStringContainsString('Lisbon', $this->claudeCalls()[0]['prompt']);
        $this->assertStringNotContainsString('chess', $this->claudeCalls()[0]['prompt']);

        await($session->stop());

        $this->assertSame(self::NEW_MEMORY, $this->memory()->read(['555', '666']));
        $this->assertSame('- The three of them run a chess club.', $this->memory()->read(['555', '666', '777']));
    }

    #[TestWith([['555', '666', '777', '888', '901'], true], 'five people')]
    #[TestWith([['555', '666', '777', '888', '901', '902'], false], 'six people')]
    #[TestWith([['555', '666', '777', '888', '901'], true, ['1234']], 'five people and a music bot')]
    public function testHasNoGroupMemoryForMorePeopleThanTheCommandsCanName(array $people, bool $remembered, array $bots = []): void
    {
        // /memory and /forget name the person using them and four others: a bigger group's memory couldn't be seen or deleted.
        $this->assertSame(5, MemoryGroup::MAX_PEOPLE);
        $this->assertSame(count(MemoryGroup::OPTIONS) + 1, MemoryGroup::MAX_PEOPLE);
        $this->memory()->save($people, self::TRIP);
        $this->inCall(...$people, ...$bots);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        await($session->stop());

        $this->assertSame($remembered, str_contains($this->claudeCalls()[0]['prompt'], 'Lisbon'));
        $this->assertCount($remembered ? 3 : 2, $this->claudeCalls());
        $this->assertSame($remembered ? self::NEW_MEMORY : self::TRIP, $this->memory()->read($people));
    }

    public function testStillUpdatesTheMemoriesWhenTheSummaryFails(): void
    {
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        // Claude gives an empty summary.
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => $this->claudeSays('')]);
        await($session->stop());

        $this->assertSame("Sorry, I couldn't summarize the call. (Claude gave an empty summary.)", end($this->sent));
        $this->assertSame(self::NEW_MEMORY, $this->memory()->read('555'));
    }

    public function testDoesNotUseAGroupMemoryWhenSomeoneInTheCallOptsOutWhileAQuestionWaitsForItsTurn(): void
    {
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Transcribing takes a while, and Bob opts out after Alice's question ended.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Hey Claude, what time is it?', 'FAKE_WHISPER_DELAY' => '0.7']);
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->logged('Utterance ended') !== [], 'the utterance to end');
        VoiceSession::optOut('666');
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        $this->assertStringNotContainsString('Lisbon', $this->claudeCalls()[0]['prompt']);

        await($session->stop());

        $this->assertCount(2, $this->claudeCalls(), 'The answer and the summary: nothing is updated.');
        $this->assertSame(self::TRIP, $this->memory()->read(['555', '666']));
    }

    public function testDoesNotSaveAMemoryWhenSomeoneOptsOutWhileClaudeIsWritingIt(): void
    {
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->holdMemoryUpdates();
        $ended = $session->stop();
        $this->waitUntil(fn () => $this->memoryUpdates() !== [], 'Claude to be asked for the new memory');
        VoiceSession::optOut('666');
        $this->releaseMemoryUpdates();
        await($ended);

        $this->assertFileDoesNotExist("{$this->memories}/groups/555-666.md");
        $this->assertSame([], $this->logged('Updated memory'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAMemoryThatCannotBeReadDoesNotStopTheOthersFromBeingUpdated(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, first.');
        $this->joins('666');
        $this->ask($vc, '555', 'Hey Claude, second.');
        // Alice's memory is only unreadable once the call is over, when it is updated first.
        chmod("{$this->memories}/555.md", 0000);
        $this->assertFalse(is_readable("{$this->memories}/555.md"), 'The test needs a user the file\'s mode applies to.');

        // PHP warns that it can't open the file, which the test expects.
        $warnings = [];
        set_error_handler(function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_WARNING);

        try {
            await($session->stop());
        } finally {
            restore_error_handler();
            chmod("{$this->memories}/555.md", 0600);
        }

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Permission denied', $warnings[0]);
        $problems = $this->loggedProblems();
        $this->assertCount(1, $problems);
        $this->assertStringStartsWith('Could not update the memory: ', $problems[0]);
        $this->assertSame(self::NEW_MEMORY, $this->memory()->read(['555', '666']));
        $this->assertSame([2], array_column($this->logged('Updated memory'), 'people'));
    }

    public function testForgettingOneMemoryLeavesWhatWasSaidForAnotherAlone(): void
    {
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, first.');
        $this->joins('666');
        $this->ask($vc, '555', 'Hey Claude, second.');

        VoiceSession::forget('555');
        await($session->stop());

        $this->assertCount(4, $this->claudeCalls(), 'Two answers, the summary, and the update of the group\'s memory only.');
        $this->assertFileDoesNotExist("{$this->memories}/555.md");
        $this->assertSame(self::NEW_MEMORY, $this->memory()->read(['555', '666']));
    }

    #[TestWith(['Carol joins'])]
    #[TestWith(['Carol joins while Bob shares his memory'])]
    #[TestWith(['Carol, who opted out, joins'])]
    #[TestWith(['Bob opts out'])]
    #[TestWith(['Bob leaves and opts out'])]
    public function testAnAnswerMadeFromAGroupMemoryIsCutOffWhenItIsNoLongerForWhoIsInTheCall(string $what): void
    {
        $this->memory()->save('666', self::BOB);
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->inCall('555', '666');
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('You two are going to Lisbon. ', 'Bob still has to book the flights.'),
            'FAKE_CLAUDE_PAUSE' => '10',
        ]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // A memory shared with the call is for whoever is in it, but that doesn't make the group's memory theirs.
        if ($what === 'Carol joins while Bob shares his memory') {
            $session->share('666');
        }

        // It happens once the first sentence was spoken, while Claude is still writing the rest.
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');
        match ($what) {
            'Carol joins', 'Carol joins while Bob shares his memory' => $this->joins('777'),
            // Who is in the call then isn't a group the bot keeps a memory of, which must not look like nobody having joined.
            'Carol, who opted out, joins' => [VoiceSession::optOut('777'), $this->joins('777')],
            'Bob opts out' => VoiceSession::optOut('666'),
            // Nobody in the channel has opted out then, but the memory is no longer used for anyone.
            'Bob leaves and opts out' => [$this->leaves('666'), VoiceSession::optOut('666')],
        };
        touch($this->claudeResume);
        $this->waitUntil(fn () => $this->logged('Claude answered') !== [], 'Claude to finish');
        $this->runFor(0.5);

        // What is left isn't spoken, and the answer, which quotes the memory, is neither posted nor kept.
        $this->assertCount(1, $this->played);
        $this->assertCount(1, glob("{$session->directory}/claude-*"), 'The rest isn\'t even synthesized.');
        $this->assertSame([], array_values(array_filter($this->sent, fn (string $message) => str_starts_with($message, '> '))));
        $this->assertStringNotContainsString('Claude:', $this->transcript($session));
        $this->assertSame(
            [['user' => '555', 'reason' => 'the people in the call changed']],
            array_map(fn (array $context) => array_slice($context, 2), $this->logged('Not answering')),
        );
        $this->assertSame(0, $this->usage()['answers']);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testTheRestOfAnAnswerMadeFromAGroupMemoryIsNotSpokenOnceSomeoneElseJoins(): void
    {
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->inCall('555', '666');
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('You two are going to Lisbon. ', 'Bob still has to book the flights.')]);
        $this->playing = ($playing = new Deferred())->promise();
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Claude wrote the whole answer, and the bot is still saying its first sentence when Carol joins.
        $this->ask($vc, '555', 'Hey Claude, what are our plans?');
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');
        $this->joins('777');
        $playing->resolve(null);
        $this->runFor(0.5);

        $this->assertCount(1, $this->played, 'The second sentence, which Carol would hear, is not spoken.');
        $this->assertSame(
            ["> **Alice:** Hey Claude, what are our plans?\nYou two are going to Lisbon. Bob still has to book the flights."],
            $this->sent,
            'It was posted before she joined.',
        );
    }

    public function testAnAnswerMadeFromAGroupMemoryGoesOnWhenOneOfThemLeaves(): void
    {
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->inCall('555', '666');
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('You two are going to Lisbon. ', 'Bob still has to book the flights.'),
            'FAKE_CLAUDE_PAUSE' => '10',
        ]);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Bob hears no more of it, and Alice is one of the two.
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');
        $this->leaves('666');
        touch($this->claudeResume);
        $this->waitUntil(fn () => count($this->played) === 2, 'the rest of the answer to be spoken');

        $this->assertSame(["> **Alice:** Hey Claude, what time is it?\nYou two are going to Lisbon. Bob still has to book the flights."], $this->sent);
        $this->assertSame([], $this->logged('Not answering'));
    }

    public function testAnAnswerMadeFromNoGroupMemoryGoesOnWhenSomeoneJoins(): void
    {
        // Alice and Bob have no memory together yet. Bob shares his own with the call, so with whoever is in it.
        $this->memory()->save('666', self::BOB);
        $this->inCall('555', '666');
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('Bob is learning to sail. ', 'He could take you along.'),
            'FAKE_CLAUDE_PAUSE' => '10',
        ]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $session->share('666');

        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');
        $this->joins('777');
        touch($this->claudeResume);
        $this->waitUntil(fn () => count($this->played) === 2, 'the rest of the answer to be spoken');

        $this->assertStringContainsString('What you remember about Bob, who shared their memory with this call', $this->claudeCalls()[0]['prompt']);
        $this->assertSame("> **Alice:** Hey Claude, what time is it?\nBob is learning to sail. He could take you along.", $this->sent[1]);
        $this->assertSame([], $this->logged('Not answering'));
    }

    public function testAMusicBotInTheChannelIsNobodyToAMemory(): void
    {
        $this->memory()->save('555', self::ALICE);
        // A memory like the ones a call made while bots still counted as people.
        $this->memory()->save(['555', '1234'], '- Alice and Jukebox listen to jazz.');
        $this->inCall('555', '1234');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        // As far as memories go, Alice is alone with the bot: her personal memory is used, and updated.
        $this->assertSame(
            "What you remember about Alice:\n\n" . self::ALICE . "\n\n"
            . "Transcript of the voice call so far:\n\nAlice: Hey Claude, what time is it?\n\n" . $this->asking('Alice', 'Hey Claude, what time is it?'),
            $this->untimed($this->claudeCalls()[0]['prompt']),
        );

        await($session->stop());

        $this->assertSame(self::NEW_MEMORY, $this->memory()->read('555'));
        $this->assertSame('- Alice and Jukebox listen to jazz.', $this->memory()->read(['555', '1234']));
        $this->assertSame([1], array_column($this->logged('Updated memory'), 'people'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAGroupIsThePeopleInTheChannelWithoutItsBots(): void
    {
        $this->memory()->save('666', self::BOB);
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->inCall('555', '666', '1234');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $session->share('666');

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        // The memory of Alice and Bob, and the one Bob shared with the call, as without the music bot.
        $this->assertSame(
            "What you remember about Alice and Bob together:\n\n" . self::TRIP . "\n\n"
            . "What you remember about Bob, who shared their memory with this call:\n\n" . self::BOB . "\n\n"
            . "Transcript of the voice call so far:\n\nAlice: Hey Claude, what time is it?\n\n" . $this->asking('Alice', 'Hey Claude, what time is it?'),
            $this->untimed($this->claudeCalls()[0]['prompt']),
        );

        await($session->stop());

        $this->assertSame(self::NEW_MEMORY, $this->memory()->read(['555', '666']));
        $this->assertSame(['555-666.md'], array_values(array_diff(scandir("{$this->memories}/groups"), ['.', '..'])));
        $this->assertSame([2], array_column($this->logged('Updated memory'), 'people'));
    }

    public function testWhatABotSaysIsKeptWithThePeopleWhoAreThere(): void
    {
        $this->inCall('555', '666', '1234');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // The music bot's audio is transcribed like anyone's.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Never gonna give you up.']);
        $this->speak($vc, ssrc: 1234, userId: '1234', seconds: 1.0);
        $this->waitUntil(fn () => $this->transcript($session) !== '', 'the transcript');

        await($session->stop());

        // It was said in front of Alice and Bob, whose memory it goes to: there is none with the music bot in it.
        $this->assertStringContainsString("Jukebox: Never gonna give you up.\n\nReply", $this->untimed($this->memoryUpdates()[0]['prompt']));
        $this->assertSame(['555-666.md'], array_values(array_diff(scandir("{$this->memories}/groups"), ['.', '..'])));
        $this->assertSame([2], array_column($this->logged('Updated memory'), 'people'));
    }

    public function testABotTalkingToTheBotWithNobodyThereIsAnsweredWithoutAnyMemory(): void
    {
        // Like the second bot of the live voice test, which asks the bot a question.
        $this->inCall('1234');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '1234', 'Hey Claude, what time is it?');

        $this->assertSame(
            "Transcript of the voice call so far:\n\nJukebox: Hey Claude, what time is it?\n\n" . $this->asking('Jukebox', 'Hey Claude, what time is it?'),
            $this->untimed($this->claudeCalls()[0]['prompt']),
        );
        $this->assertSame(["> **Jukebox:** Hey Claude, what time is it?\n" . self::ANSWER], $this->sent);

        await($session->stop());

        $this->assertCount(2, $this->claudeCalls(), 'The answer and the summary: nothing is remembered of a bot.');
        $this->assertDirectoryDoesNotExist($this->memories);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testWhatClaudeSaysItRemembersAboutSomeoneIsNotRememberedForTheGroup(): void
    {
        $this->memory()->save('555', self::ALICE);
        $this->inCall('555', '666');
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('You are building a game called Bananas.')]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Claude answers from Alice's personal memory, with Bob listening.
        $this->ask($vc, '555', 'Hey Claude, what do you remember about me?');
        $this->assertStringContainsString("What you remember about Alice:\n\n" . self::ALICE, $this->claudeCalls()[0]['prompt']);

        await($session->stop());

        // The group's memory is made from what its people said. What Claude answered is in the call's transcript only.
        $this->assertSame(
            "The current memory:\n\nNothing yet.\n\n"
            . "What was said since it was last updated:\n\nAlice: Hey Claude, what do you remember about me?\n\nReply with the new memory.",
            $this->untimed($this->memoryUpdates()[0]['prompt']),
        );
        $this->assertStringContainsString('Claude: You are building a game called Bananas.', $this->transcript($session));
        $this->assertSame([2], array_column($this->logged('Updated memory'), 'people'));
    }

    public function testWhatClaudeSaysFromASharedMemoryIsNotRememberedForTheGroup(): void
    {
        $this->memory()->save('666', self::BOB);
        $this->inCall('555', '666');
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('Bob is learning to sail.')]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $session->share('666');

        $this->ask($vc, '555', 'Hey Claude, what is Bob up to?');
        $this->assertStringContainsString("What you remember about Bob, who shared their memory with this call:\n\n" . self::BOB, $this->claudeCalls()[0]['prompt']);

        await($session->stop());

        // Sharing lasts until the call ends: it must not live on in the memory of Alice and Bob.
        $update = $this->untimed($this->memoryUpdates()[0]['prompt']);
        $this->assertStringContainsString("Alice: Hey Claude, what is Bob up to?\n\nReply with the new memory.", $update);
        $this->assertStringNotContainsString('sail', $update);
    }

    public function testWhatClaudeSaysFromASharedMemoryIsNotRememberedForSomeoneAloneWithTheBot(): void
    {
        $this->memory()->save('666', self::BOB);
        $this->inCall('555', '666');
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeStream('Bob is learning to sail.')]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        // Bob shares his memory and leaves: it stays shared until the call ends, and Alice is alone with the bot.
        $session->share('666');
        $this->leaves('666');

        $this->ask($vc, '555', 'Hey Claude, what is Bob up to?');
        $this->assertStringContainsString("What you remember about Bob, who shared their memory with this call:\n\n" . self::BOB, $this->claudeCalls()[0]['prompt']);

        await($session->stop());

        // What Claude told her from Bob's memory must not end up in hers.
        $this->assertSame(
            "The current memory:\n\nNothing yet.\n\n"
            . "What was said since it was last updated:\n\nAlice: Hey Claude, what is Bob up to?\n\nReply with the new memory.",
            $this->untimed($this->memoryUpdates()[0]['prompt']),
        );
        $this->assertSame([1], array_column($this->logged('Updated memory'), 'people'));
    }

    #[TestWith(['NOTHING'], 'nothing worth remembering')]
    #[TestWith([''], 'an empty answer')]
    public function testKeepsAGroupMemoryAsItIsWhenClaudeReturnsNoNewOne(string $answer): void
    {
        $this->memory()->save(['555', '666'], self::TRIP);
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT_MEMORY' => $this->claudeSays($answer)]);
        await($session->stop());

        $this->assertCount(1, $this->memoryUpdates());
        $this->assertSame(self::TRIP, $this->memory()->read(['555', '666']));
        $this->assertSame([], $this->logged('Updated memory'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testCreatesNoGroupMemoryWhenNothingIsWorthRemembering(): void
    {
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT_MEMORY' => $this->claudeSays('NOTHING')]);
        await($session->stop());

        $this->assertStringContainsString(
            'When there is no memory yet and nothing worth remembering was said, reply with NOTHING alone.',
            $this->memoryUpdates()[0]['system'],
        );
        $this->assertDirectoryDoesNotExist($this->memories);
        $this->assertSame([], $this->logged('Updated memory'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testTwoCallsOfTheSamePeopleUpdateTheirMemoryOneAfterTheOther(): void
    {
        $this->memory()->save(['555', '666'], self::TRIP);
        [$first, $second] = $this->twoCallsOfAliceAndBob();

        // One call ends, and Claude is still writing its new memory when the other one ends too.
        $ended = $this->endOneAfterTheOther($first, $second);
        $this->runFor(0.3);
        $this->assertCount(1, $this->memoryUpdates(), 'The other call\'s update waits.');

        // The update that waited gets another answer, to tell the two apart.
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT_MEMORY' => $this->claudeSays(self::NEW_MEMORY . "\n- Updated again.")]);
        $this->releaseMemoryUpdates();
        await($ended);

        // It read what the one before it saved, so neither call's update is lost.
        [$one, $other] = array_column($this->memoryUpdates(), 'prompt');
        $this->assertStringStartsWith("The current memory:\n\n" . self::TRIP . "\n\nWhat was said since", $one);
        $this->assertStringStartsWith("The current memory:\n\n" . self::NEW_MEMORY . "\n\nWhat was said since", $other);
        $this->assertSame(self::NEW_MEMORY . "\n- Updated again.", $this->memory()->read(['555', '666']));
        $this->assertSame([2, 2], array_column($this->logged('Updated memory'), 'people'));
        $this->assertLogsNeverMention('Lisbon', 'Updated by Claude', 'Monday', 'week');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAnUpdateForgottenWhileItWaitsForAnotherIsNeverGivenToClaude(): void
    {
        [$first, $second] = $this->twoCallsOfAliceAndBob();

        $ended = $this->endOneAfterTheOther($first, $second);
        VoiceSession::forget(['555', '666']);
        $this->releaseMemoryUpdates();
        await($ended);

        // The first update isn't saved, and what was said in the other call never reaches Claude.
        $this->assertCount(1, $this->memoryUpdates());
        $this->assertDirectoryDoesNotExist($this->memories);
        $this->assertSame([], $this->logged('Updated memory'));
        $this->assertSame([], $this->loggedProblems());
    }

    /**
     * Alice and Bob are in a call in each of two servers, and Alice asked Claude something in both.
     *
     * @return array{VoiceSession, VoiceSession}
     */
    private function twoCallsOfAliceAndBob(): array
    {
        $this->inCall('555', '666');

        foreach ([self::BOT_ID, '555', '666'] as $userId) {
            $this->voiceStates[] = $this->inVoice($userId, '201');
        }

        $first = VoiceSession::start($firstVc = $this->voiceClient($firstChannel = $this->voiceChannel()), $firstChannel, $this->discord);
        $second = VoiceSession::start($secondVc = $this->voiceClient($secondChannel = $this->voiceChannel('201', '101')), $secondChannel, $this->discord);

        $this->ask($firstVc, '555', 'Hey Claude, we fly on Monday.');
        // The other call's voice client knows Alice by another SSRC.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Hey Claude, we stay for a week.']);
        $this->speak($secondVc, ssrc: 1555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->sent) === 2, 'the answer in the other call');

        return [$first, $second];
    }

    /**
     * Ends two calls, the second one while Claude is still writing the new memory the first one asked for,
     * which it goes on doing until {@see releaseMemoryUpdates()}.
     *
     * One after the other, not at once: Claude's stand-ins share the file they log their call in, so two of
     * them starting together would mix up what the test reads of them.
     *
     * @return PromiseInterface<mixed> Resolves once both calls are over.
     */
    private function endOneAfterTheOther(VoiceSession $first, VoiceSession $second): PromiseInterface
    {
        $this->holdMemoryUpdates();
        $ended = $first->stop();
        $this->waitUntil(fn () => $this->memoryUpdates() !== [], 'Claude to be asked for the new memory');
        $ended = all([$ended, $second->stop()]);
        // By then the other call has asked for its update too.
        $this->waitUntil(fn () => count($this->logged('Summarized the call')) === 2, 'the other call to be summarized');

        return $ended;
    }
}
