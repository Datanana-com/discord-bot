<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Privacy\OptOuts;
use App\Voice\VoiceSession;

use function React\Async\await;

/**
 * What the bot remembers of calls: a personal memory for someone alone with the bot, and a group
 * memory for each group of people in the voice channel. Alice is 555, Bob 666 and Carol 777; the bot is 999.
 */
final class VoiceMemoryTest extends VoiceTestCase
{
    private const string ALICE = '- Is building a game called Bananas.';

    private const string BOB = '- Is learning to sail.';

    private const string TRIP = '- Alice and Bob plan a trip to Lisbon.';

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
            . "Transcript of the voice call so far:\n\nAlice: Hey Claude, what time is it?\n\nAlice is talking to you. Reply to their last message.",
            $answering['prompt'],
        );
        // It is told whose memory is whose, and that the whole call hears its answer.
        $this->assertStringContainsString('what you remember about the person talking to you, and what you remember about everyone in the call together', $answering['system']);
        $this->assertStringContainsString('the other people in the call have memories of their own, which you don\'t get', $answering['system']);
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
            . "Transcript of the voice call so far:\n\nAlice: Hey Claude, what time is it?\n\nAlice is talking to you. Reply to their last message.",
            $answering,
        );
        $this->assertStringNotContainsString('sail', $answering);

        await($session->stop());

        // Only the group's memory is updated, from the call's transcript. The personal ones are never updated from a call with others.
        $calls = $this->claudeCalls();
        $this->assertCount(3, $calls);
        $this->assertSame(
            "The current memory:\n\n" . self::TRIP . "\n\n"
            . "What was said since it was last updated:\n\nAlice: Hey Claude, what time is it?\nClaude: " . self::ANSWER . "\n\nReply with the new memory.",
            $this->untimed($calls[2]['prompt']),
        );
        $this->assertStringStartsWith("You keep a Discord bot's memory of a group of people", $calls[2]['system']);
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
            . "What was said since it was last updated:\n\nAlice: Hey Claude, second.\nClaude: " . self::ANSWER . "\n\nReply with the new memory.",
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
        $this->voiceStates[] = (object) ['user_id' => '555', 'channel_id' => '200'];
        $this->voiceStates[] = (object) ['user_id' => '666', 'channel_id' => '200'];
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

        $this->assertCount(2, $this->claudeCalls(), 'The answer and the summary: there is nothing to remember.');
        $this->assertFileDoesNotExist("{$this->memories}/555.md");
        $this->assertSame([], $this->logged('Updated memory'));
    }

    public function testAMemoryForgottenWhileItIsBeingUpdatedStaysForgotten(): void
    {
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, my PIN is 1234.');

        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0.5']);
        $ended = $session->stop();
        $this->waitUntil(fn () => count($this->claudeCalls()) === 3, 'Claude to be asked for the new memory');
        VoiceSession::forget('555');
        await($ended);

        $this->assertFileDoesNotExist("{$this->memories}/555.md");
        $this->assertSame([], $this->logged('Updated memory'));
        $this->assertSame([], $this->loggedProblems());
    }
}
