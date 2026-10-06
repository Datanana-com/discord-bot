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
