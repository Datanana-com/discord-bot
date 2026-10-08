<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Voice\VoiceSession;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which player a call plays its sentences with: VOICE_PLAYER. The feature tests otherwise play with the voice
 * library, whose voice client plays nothing here; VoiceCallTest plays with the bot's own player and real audio.
 */
final class VoicePlayerTest extends VoiceTestCase
{
    public function testPlaysWithTheVoiceLibraryWhenToldSoInAnyCase(): void
    {
        $this->setEnv(['VOICE_PLAYER' => ' Library ']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be handed to the library');

        $this->assertCount(1, $this->played);
        $this->assertSame([], $this->loggedProblems());
    }

    /**
     * @param list<string> $warnings
     */
    #[DataProvider('playersThatAreTheBot')]
    public function testPlaysItselfUnlessToldOtherwise(string $player, array $warnings): void
    {
        $this->setEnv(['VOICE_PLAYER' => $player]);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // A player nobody knows is said so when the call starts, once.
        $this->assertSame($warnings, $this->loggedProblems());

        // The bot sends the packets itself, and never hands the file to the library. The file of these tests holds no
        // Ogg Opus, so the sentence can't be played, which the call is told like any sentence that can't be.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->sent) === 2, 'the channel to be told');

        $this->assertSame([], $this->played, 'Nothing was handed to the library.');
        $this->assertStringEndsWith('It is a quarter past four.', $this->sent[0]);
        $this->assertSame("Sorry, I couldn't say that out loud. The bot's logs say why.", $this->sent[1]);
        $problems = array_values(array_diff($this->loggedProblems(), $warnings));
        $this->assertCount(1, $problems);
        $this->assertStringStartsWith('Voice reply failed: Could not play ', $problems[0]);
        $this->assertStringEndsWith('claude-2.ogg: Not an Ogg Opus file: it does not start with the OpusHead and OpusTags headers.', $problems[0]);
        $this->assertSame('speech', $this->logged($problems[0])[0]['step']);
        $this->assertLogsNeverMention('quarter past four');
    }

    public function testTheBotStartsOnASentenceWhileItsFfmpegIsStillEncodingIt(): void
    {
        // The sentence's ffmpeg has written the first of it, and takes its time over the rest.
        touch($hold = "{$this->recordings}/ffmpeg.hold");
        $this->setEnv(['VOICE_PLAYER' => 'bot']);
        $this->setProcessEnv(['FAKE_FFMPEG_HOLD' => $hold, 'FAKE_FFMPEG_STARTS' => '1']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // The bot's own player takes the sentence from there, which these tests see by what it makes of their
        // files: they hold no Ogg Opus, and it says so before the ffmpeg has ended.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->loggedProblems() !== [], 'the player to be given the sentence');

        $this->assertFileExists($hold);
        $this->assertStringStartsWith('Voice reply failed: Could not play ', $this->loggedProblems()[0]);
        unlink($hold);
    }

    public function testOkayAndTheBotsOwnSentencesGoThroughThePlayerToo(): void
    {
        $this->setEnv(['VOICE_PLAYER' => 'bot']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // The stop phrase: "Okay." can't be played from these tests' files either, which is logged, and nothing
        // goes to the library.
        $this->ask($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->sent) === 2, 'the channel to be told the answer could not be spoken');
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Stop, Claude.']);
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => preg_grep('/^Could not say okay: /', $this->loggedProblems()) !== [], 'okay to fail');

        // Nor can "Sorry, something went wrong.", which the bot says on its own when whisper fails.
        $this->setProcessEnv(['FAKE_WHISPER_EXIT' => '1']);
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => preg_grep('/^Could not say sorry: /', $this->loggedProblems()) !== [], 'sorry to fail');

        $this->assertSame([], $this->played, 'Nothing was handed to the library.');
        $problems = implode("\n", $this->loggedProblems());
        $this->assertSame(3, preg_match_all('/Could not play .*claude-\d+\.ogg: Not an Ogg Opus file/', $problems), 'The answer, okay, and sorry.');
        $this->assertLogsNeverMention('quarter past four');
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function playersThatAreTheBot(): iterable
    {
        yield 'bot' => ['bot', []];
        yield 'BOT, with spaces' => [' BOT ', []];
        yield 'not set' => ['', []];
        yield 'a player nobody knows' => ['ffplay', ['VOICE_PLAYER is neither bot nor library: the bot sends the packets of its sentences itself.']];
    }
}
