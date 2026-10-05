<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Assistant\DirectChat;
use PHPUnit\Framework\Attributes\DataProvider;
use React\Http\Message\Response;

final class DirectVoiceMessageTest extends VoiceTestCase
{
    use ChatsInDirectMessages;

    private const string SAID = 'When should we ship the beta?';

    private const string NOT_HEARD = "I couldn't hear anything in that voice message.";

    private const string COULD_NOT = "Sorry, I couldn't transcribe that voice message.";

    /** @var list<string> The files in the folder for voice messages before the test. */
    private array $voiceFilesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDirectMessages();
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => self::SAID]);
        $this->voiceFilesBefore = $this->voiceFiles();
    }

    protected function tearDown(): void
    {
        $this->endDirectMessages();
        parent::tearDown();
    }

    public function testAnswersAVoiceMessageAndQuotesWhatItHeard(): void
    {
        $this->memory()->save('555', '- Is building a game called Bananas.');

        $this->writeVoice(4.2);
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        // The answer starts with what the bot heard, so the person can tell when whisper misheard.
        $this->assertSame(['> 🎤 ' . self::SAID . "\n" . self::ANSWER], $this->sent);
        $this->assertSame([], $this->played, 'Nothing is spoken.');

        // The bot shows it is typing from the start, and reads the conversation once it knows what was said.
        $this->assertSame(['typing 555', 'history 555', 'sent 555'], $this->events);

        // The same prompt as for a typed message: the memory, the conversation, and the message to reply to.
        // The voice message itself has no text in the DM.
        $this->assertSame(
            "What you remember about Alice:\n\n- Is building a game called Bananas.\n\n"
            . "The recent messages of your chat with Alice:\n\n\n\n"
            . 'Reply to this message from Alice:' . "\n\n" . self::SAID,
            $this->lastPrompt(),
        );
        $this->assertStringStartsWith("You are Claude, this person's personal assistant", $this->lastSystemPrompt());
        $this->assertClaudeWasRestricted();

        // Downloaded from Discord, converted by ffmpeg and transcribed by whisper.cpp.
        $this->assertSame(
            [['host' => 'cdn.discordapp.com', 'target' => '/attachments/1/2/voice-message.ogg?ex=abc&hm=def']],
            $this->cdn->requests,
        );
        $ffmpeg = file_get_contents($this->ffmpegLog);
        $this->assertMatchesRegularExpression('#^arg=-loglevel\narg=error\narg=-y\narg=-i\narg=\S+/discord-bot-voice-messages/[0-9a-f]{16}\.ogg\n#', $ffmpeg);
        $this->assertStringContainsString("arg=-ar\narg=16000\narg=-ac\narg=1\narg=-c:a\narg=pcm_s16le\n", $ffmpeg);
        $this->assertMatchesRegularExpression(
            '#arg=--model\narg=\S+/ggml-base\.bin\narg=--language\narg=[a-z]+\narg=--no-timestamps\narg=--no-prints\narg=--file\narg=\S+/discord-bot-voice-messages/[0-9a-f]{16}\.wav\n$#',
            file_get_contents($this->whisperLog),
        );
        $this->assertNoVoiceFilesLeft();

        $transcribed = $this->logged('Transcribed a voice message');
        $this->assertCount(1, $transcribed);
        $this->assertSame(['user', 'seconds', 'ms', 'characters'], array_keys($transcribed[0]));
        $this->assertSame('555', $transcribed[0]['user']);
        $this->assertSame(4.2, $transcribed[0]['seconds']);
        $this->assertIsInt($transcribed[0]['ms']);
        $this->assertSame(mb_strlen(self::SAID), $transcribed[0]['characters']);
        $answered = $this->logged('Answered a DM');
        $this->assertCount(1, $answered);
        $this->assertSame(mb_strlen(self::ANSWER), $answered[0]['characters'], 'The quote is not part of the answer.');
        $this->assertLogsNeverMention('ship the beta', 'Bananas', 'voice-message.ogg', 'hm=def');
        $this->assertSame([], $this->loggedProblems());
        $this->assertNotContains(8.0, $this->timers->pending(), 'The bot stops showing it is typing.');
    }

    public function testKeepsTheWordsOfAVoiceMessageInLaterPrompts(): void
    {
        $this->writeVoice();
        $this->waitUntil(fn () => count($this->sent) === 1, 'the answer');

        // The DM shows the voice message without text, so the bot's quote is what holds its words.
        $this->chat('Friday, then.');

        $this->assertStringContainsString(
            "your chat with Alice:\n\nClaude: > 🎤 " . self::SAID . "\n" . self::ANSWER . "\nAlice: Friday, then.\n\nReply to this message",
            $this->lastPrompt(),
        );
    }

    public function testRemembersWhatWasSaidInAVoiceMessageLikeATypedOne(): void
    {
        $this->writeVoice();
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');
        $this->pause('- Ships its beta on Friday.');

        // What was heard, without the quote, and what Claude answered.
        $this->assertSame(
            "The current memory:\n\nNothing yet.\n\nWhat was said since it was last updated:\n\n"
            . 'Alice: ' . self::SAID . "\nClaude: " . self::ANSWER . "\n\nReply with the new memory.",
            $this->lastPrompt(),
        );
        $this->assertSame('- Ships its beta on Friday.', $this->memory()->read('555'));
        $this->assertLogsNeverMention('ship the beta');
    }

    public function testKeepsVoiceAndTypedMessagesInOrder(): void
    {
        // The voice message takes a while to transcribe, and the typed one arrives meanwhile.
        $this->setProcessEnv(['FAKE_WHISPER_DELAY' => '0.4']);

        $this->writeVoice();
        $this->write('And the release notes?');
        $this->waitUntil(fn () => count($this->sent) === 2, 'both answers');

        // Answered one at a time, in the order they were sent.
        $this->assertSame(['typing 555', 'history 555', 'sent 555', 'typing 555', 'history 555', 'sent 555'], $this->events);
        $this->assertSame(['> 🎤 ' . self::SAID . "\n" . self::ANSWER, self::ANSWER], $this->sent);
        $this->assertStringEndsWith(
            "Alice: And the release notes?\nClaude: > 🎤 " . self::SAID . "\n" . self::ANSWER . "\n\nReply to this message from Alice:\n\nAnd the release notes?",
            $this->lastPrompt(),
        );

        // And remembered in that order too, though the typed message was known first.
        $this->pause('- Ships its beta on Friday.');
        $this->assertStringContainsString(
            'What was said since it was last updated:' . "\n\nAlice: " . self::SAID . "\nAlice: And the release notes?\nClaude: " . self::ANSWER . "\nClaude: " . self::ANSWER . "\n\n",
            $this->lastPrompt(),
        );
    }

    public function testSaysSoInsteadOfAskingClaudeWhenNothingCouldBeHeard(): void
    {
        // Whisper only prints its annotation for silence.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => '']);

        $this->writeVoice();
        $this->waitUntil(fn () => $this->sent !== [], 'the reply');

        $this->assertSame([self::NOT_HEARD], $this->sent);
        $this->assertSame(['typing 555', 'sent 555'], $this->events, 'The conversation is not even read.');
        $this->assertFileDoesNotExist($this->claudeLog, 'Claude was not asked.');
        $this->assertSame(0, $this->logged('Transcribed a voice message')[0]['characters']);
        $this->assertSame([], $this->logged('Answered a DM'));
        $this->assertNoVoiceFilesLeft();
        $this->assertNotContains(8.0, $this->timers->pending(), 'The bot stops showing it is typing.');

        // There is nothing to remember about it.
        $this->assertSame(1, $this->timers->elapse(600.0));
        $this->runFor(0.3);
        $this->assertFileDoesNotExist($this->claudeLog, 'No memory update was asked for.');
    }

    public function testRefusesAVoiceMessageOfOverFiveMinutesWithoutDownloadingIt(): void
    {
        $this->writeVoice(300.1);
        $this->waitUntil(fn () => $this->sent !== [], 'the reply');

        $this->assertSame(
            ['That voice message is longer than 5 minutes, and transcribing it would take too long. Could you send a shorter one, or write it down?'],
            $this->sent,
        );
        $this->assertSame([], $this->cdn->requests, 'Nothing was downloaded.');
        $this->assertFileDoesNotExist($this->ffmpegLog);
        $this->assertFileDoesNotExist($this->claudeLog, 'Claude was not asked.');
        $this->assertSame([], $this->loggedProblems(), 'It is not a failure.');
        $this->assertSame([], $this->logged('Transcribed a voice message'));
        $this->assertNotContains(8.0, $this->timers->pending(), 'The bot stops showing it is typing.');
    }

    public function testListensToAVoiceMessageOfExactlyFiveMinutes(): void
    {
        $this->writeVoice(300.0);
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        $this->assertSame(['> 🎤 ' . self::SAID . "\n" . self::ANSWER], $this->sent);
    }

    public function testRefusesAVoiceMessageLongerThanItsSenderSaysItIs(): void
    {
        // Discord's client says 4 seconds, but the audio is longer than 5 minutes: ffmpeg stops a second after the limit.
        $this->setProcessEnv(['FAKE_FFMPEG_BYTES' => (string) (301 * 32000)]);

        $this->writeVoice(4.0);
        $this->waitUntil(fn () => $this->sent !== [], 'the reply');

        $this->assertStringStartsWith('That voice message is longer than 5 minutes', $this->sent[0]);
        $this->assertStringContainsString("arg=-t\narg=301\n", file_get_contents($this->ffmpegLog));
        $this->assertFileDoesNotExist($this->whisperLog, 'It was not transcribed.');
        $this->assertFileDoesNotExist($this->claudeLog, 'Claude was not asked.');
        $this->assertNoVoiceFilesLeft();
    }

    public function testListensToAVoiceMessageThatIsAFewSamplesOverFiveMinutesOnceConverted(): void
    {
        // Discord says 300 seconds, and the converted audio is 300.25: rounding, not a message that is too long.
        $this->setProcessEnv(['FAKE_FFMPEG_BYTES' => (string) (300 * 32000 + 8000)]);

        $this->writeVoice(300.0);
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        $this->assertSame(['> 🎤 ' . self::SAID . "\n" . self::ANSWER], $this->sent);
        $this->assertNoVoiceFilesLeft();
    }

    public function testTellsThePersonWhenTheDownloadFails(): void
    {
        $this->cdn->response = new Response(404, [], 'Not found');

        $this->writeVoice();
        $this->waitUntil(fn () => $this->sent !== [], 'the reply');

        $this->assertSame([self::COULD_NOT], $this->sent);
        $this->assertSame(['Could not transcribe a voice message: HTTP status code 404 (Not Found)'], $this->loggedProblems());
        $this->assertFileDoesNotExist($this->ffmpegLog);
        $this->assertFileDoesNotExist($this->claudeLog, 'Claude was not asked.');
        $this->assertNoVoiceFilesLeft();
        $this->assertNotContains(8.0, $this->timers->pending(), 'The bot stops showing it is typing.');

        // The person's next message is answered like any other, and the failed one is not remembered.
        $this->chat('Hello again.');
        $this->assertSame(self::ANSWER, $this->sent[1]);
        $this->pause('- Said hello.');
        $this->assertStringContainsString("since it was last updated:\n\nAlice: Hello again.\nClaude: " . self::ANSWER . "\n\n", $this->lastPrompt());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignUrls(): array
    {
        return [
            'another host' => ['https://example.com/voice-message.ogg?ex=abc&hm=def'],
            'plain http' => ['http://cdn.discordapp.com/voice-message.ogg'],
            'credentials in front of another host' => ['https://cdn.discordapp.com@example.com/voice-message.ogg'],
        ];
    }

    #[DataProvider('foreignUrls')]
    public function testDoesNotDownloadFromOutsideDiscordsHosts(string $url): void
    {
        $this->writeVoice(url: $url);
        $this->waitUntil(fn () => $this->sent !== [], 'the reply');

        $this->assertSame([self::COULD_NOT], $this->sent);
        $this->assertSame([], $this->cdn->requests, 'Nothing was requested.');
        $this->assertCount(1, $this->loggedProblems());
        $this->assertStringStartsWith('Could not transcribe a voice message: Not downloading from ', $this->loggedProblems()[0]);
        $this->assertLogsNeverMention('voice-message.ogg', 'hm=def');
        $this->assertFileDoesNotExist($this->claudeLog, 'Claude was not asked.');
        $this->assertNoVoiceFilesLeft();
    }

    public function testDoesNotFollowARedirectOutsideDiscordsHosts(): void
    {
        $this->cdn->response = new Response(302, ['Location' => 'https://example.com/voice-message.ogg'], '');

        $this->writeVoice();
        $this->waitUntil(fn () => $this->sent !== [], 'the reply');

        $this->assertSame([self::COULD_NOT], $this->sent);
        $this->assertCount(1, $this->cdn->requests);
        $this->assertSame(['Could not transcribe a voice message: Discord answered the download with HTTP status 302.'], $this->loggedProblems());
        $this->assertNoVoiceFilesLeft();
    }

    public function testTellsThePersonWhenFfmpegFails(): void
    {
        $this->setEnv(['FFMPEG_BINARY' => '/this/ffmpeg/does/not/exist']);

        $this->writeVoice();
        $this->waitUntil(fn () => $this->sent !== [], 'the reply');

        $this->assertSame([self::COULD_NOT], $this->sent);
        $this->assertCount(1, $this->cdn->requests, 'The audio had been downloaded.');
        $this->assertCount(1, $this->loggedProblems());
        $this->assertStringStartsWith('Could not transcribe a voice message: /this/ffmpeg/does/not/exist exited with code 127', $this->loggedProblems()[0]);
        $this->assertFileDoesNotExist($this->claudeLog, 'Claude was not asked.');
        $this->assertNoVoiceFilesLeft();
    }

    public function testNeverLogsWhatWhisperPrintedBeforeFailing(): void
    {
        // Whisper prints what it heard on its standard output, and the failure's message could repeat that.
        $this->setProcessEnv(['FAKE_WHISPER_EXIT' => '1']);

        $this->writeVoice();
        $this->waitUntil(fn () => $this->sent !== [], 'the reply');

        $this->assertSame([self::COULD_NOT], $this->sent);
        $this->assertCount(1, $this->loggedProblems());
        $this->assertMatchesRegularExpression('#^Could not transcribe a voice message: \S+/fake-whisper exited with code 1$#', $this->loggedProblems()[0]);
        $this->assertLogsNeverMention('ship the beta');
        $this->assertFileDoesNotExist($this->claudeLog, 'Claude was not asked.');
        $this->assertNoVoiceFilesLeft();
    }

    public function testTellsThePersonWhyClaudeCouldNotAnswerAVoiceMessage(): void
    {
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => json_encode(['type' => 'result', 'is_error' => true, 'result' => 'Usage limit reached']),
            'FAKE_CLAUDE_EXIT' => '1',
        ]);

        $this->writeVoice();
        $this->waitUntil(fn () => $this->sent !== [], 'the reply');

        // The quote is still there: the DM shows the voice message without text.
        $this->assertSame(['> 🎤 ' . self::SAID . "\nSorry, I couldn't get an answer from Claude. (Claude Code: Usage limit reached)"], $this->sent);
        $this->assertSame(['Could not answer a DM: Claude Code: Usage limit reached'], $this->loggedProblems());
        $this->assertNoVoiceFilesLeft();
        $this->assertNotContains(8.0, $this->timers->pending(), 'The bot stops showing it is typing.');
    }

    public function testForgetsAVoiceMessageTheTranscriptionOfWhichWasStillRunning(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_DELAY' => '0.4']);

        $this->writeVoice();
        DirectChat::forget('555');
        $this->waitUntil(fn () => $this->sent !== [], 'the answer');

        // Still answered, but neither the message nor its answer is remembered.
        $this->assertSame(['> 🎤 ' . self::SAID . "\n" . self::ANSWER], $this->sent);
        $this->assertSame(0, $this->timers->elapse(600.0), 'No memory update is waiting.');
        $this->assertStringStartsWith('What you remember about Alice:', $this->lastPrompt(), 'No memory update was asked for.');

        // What Alice says next is remembered, without the voice message that was forgotten.
        $this->chat('Hello again.');
        $this->pause('- Said hello.');
        $this->assertSame(
            "The current memory:\n\nNothing yet.\n\nWhat was said since it was last updated:\n\nAlice: Hello again.\nClaude: " . self::ANSWER . "\n\nReply with the new memory.",
            $this->lastPrompt(),
        );
    }

    public function testRemembersEveryVoiceMessageSentInARow(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_DELAY' => '0.3']);

        $this->writeVoice();
        $this->writeVoice();
        $this->waitUntil(fn () => count($this->sent) === 2, 'both answers');
        $this->pause('- Ships its beta on Friday.');

        $this->assertStringContainsString(
            "since it was last updated:\n\nAlice: " . self::SAID . "\nAlice: " . self::SAID . "\nClaude: " . self::ANSWER . "\nClaude: " . self::ANSWER . "\n\n",
            $this->lastPrompt(),
        );
    }

    public function testLeavesAVoiceMessageStillWaitingToBeTranscribedForTheNextMemoryUpdate(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0.4', 'FAKE_WHISPER_DELAY' => '0.8']);

        // The conversation pauses while Claude still answers: the memory update waits for its turn.
        $this->write('First.');
        $this->assertSame(1, $this->timers->elapse(600.0));
        // The voice message is next in line after that update.
        $this->writeVoice();

        $this->waitUntil(fn () => is_file($this->claudeLog) && str_starts_with($this->lastPrompt(), 'The current memory'), 'the memory update');

        // The update has what is known: no empty line stands in for the voice message.
        $this->assertSame(
            "The current memory:\n\nNothing yet.\n\nWhat was said since it was last updated:\n\nAlice: First.\nClaude: " . self::ANSWER . "\n\nReply with the new memory.",
            $this->lastPrompt(),
        );
        $this->waitUntil(fn () => count($this->logged('Updated memory')) === 1, 'the update');

        // A message typed while the voice message is transcribed comes after it in the next update.
        $this->write('Second.');
        $this->waitUntil(fn () => count($this->sent) === 3, 'all three answers');
        $this->pause('- Ships its beta on Friday.');

        $this->assertStringContainsString(
            "since it was last updated:\n\nAlice: " . self::SAID . "\nAlice: Second.\nClaude: " . self::ANSWER . "\nClaude: " . self::ANSWER . "\n\n",
            $this->lastPrompt(),
        );
    }

    public function testLeavesVoiceMessagesInServersAndFromBotsAlone(): void
    {
        $voice = (object) ['url' => 'https://cdn.discordapp.com/attachments/1/2/voice-message.ogg', 'duration_secs' => 4.2];

        $this->write('', guildId: self::GUILD_ID, attachment: $voice);
        $this->write('', userId: '999', name: 'Bot', bot: true, attachment: $voice);
        // A picture, or another attachment that is not a voice message, has no text to answer.
        $this->write('');
        $this->runFor(0.3);

        $this->assertSame([], $this->events);
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->cdn->requests);
        $this->assertSame([], $this->timers->pending());
    }

    /**
     * Downloaded and converted files are only kept while the message is transcribed.
     */
    private function assertNoVoiceFilesLeft(): void
    {
        $this->assertSame($this->voiceFilesBefore, $this->voiceFiles(), 'Downloaded and converted files are deleted.');
    }

    /**
     * The files in the bot's folder for voice messages, in the system's temp folder: shared with whatever else
     * runs on this machine, so the tests only look at what they changed.
     *
     * @return list<string>
     */
    private function voiceFiles(): array
    {
        $folder = sys_get_temp_dir() . '/discord-bot-voice-messages';

        return is_dir($folder) ? array_values(array_diff(scandir($folder), ['.', '..'])) : [];
    }

    /**
     * Ten minutes pass without a message from Alice, and Claude returns the new memory.
     */
    private function pause(string $newMemory): void
    {
        $updates = count($this->logged('Updated memory'));
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => $this->claudeSays($newMemory)]);
        $this->assertSame(1, $this->timers->elapse(600.0), 'One wait of ten minutes is over.');
        $this->waitUntil(fn () => count($this->logged('Updated memory')) > $updates, 'the memory');
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => $this->claudeSays(self::ANSWER)]);
    }
}
