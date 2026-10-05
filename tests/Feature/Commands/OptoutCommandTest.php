<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Commands\Global\OptoutCommand;
use App\Privacy\OptOuts;
use App\Voice\VoiceSession;

use function React\Async\await;

final class OptoutCommandTest extends CommandTestCase
{
    private const string OPTED_OUT = 'You opted out: I no longer record, transcribe or answer you, in any server.'
        . ' If I am recording a call you are in, what you say from now on is dropped and your recording of it is deleted when the call ends; what was already transcribed stays.'
        . ' Use /optin to undo this.';

    public function testOptsOutWhoeverUsedIt(): void
    {
        (new OptOuts())->add('666');

        $this->optOut();

        // Alice used it, in a server: it counts everywhere, as the list has no servers.
        $this->assertSame(['555', '666'], (new OptOuts())->all());
        $this->assertSame([['content' => self::OPTED_OUT, 'ephemeral' => true]], $this->responses);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testWorksInADirectMessage(): void
    {
        (new OptoutCommand($this->discord))->handle($this->interaction(null, guildId: null, userId: '666'));

        $this->assertSame(['666'], (new OptOuts())->all());
        $this->assertSame([['content' => self::OPTED_OUT, 'ephemeral' => true]], $this->responses);
    }

    public function testSaysSoWhenAlreadyOptedOut(): void
    {
        (new OptOuts())->add('555');

        $this->optOut();

        $this->assertSame(['555'], (new OptOuts())->all());
        $this->assertSame(
            [['content' => 'You had already opted out: I don\'t record, transcribe or answer you. Use /optin to undo this.', 'ephemeral' => true]],
            $this->responses,
        );
    }

    public function testTakesEffectRightAwayInACallInProgress(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Sounds good.']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->transcript($session) !== '', 'the transcript');

        $this->optOut();

        // What Alice says from now on is dropped, while Bob is still transcribed.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 2.0);
        $this->waitUntil(fn () => str_contains($this->transcript($session), 'Bob'), 'Bob to be transcribed');
        $this->runFor(0.5);

        $this->assertMatchesRegularExpression(
            '/^\[[\d:]+\] Alice: Sounds good\.\n\[[\d:]+\] Bob: Sounds good\.\n$/',
            $this->transcript($session),
            'What she said before stays in the transcript.',
        );
        $this->assertSame([], glob("{$session->directory}/utterances/*"));
        $this->assertSame([['user' => '555']], array_map(fn (array $context) => array_slice($context, 2), $this->logged('Skipping a speaker who opted out')));
        $this->assertSame(['555', '666'], array_column($this->logged('Utterance ended'), 'user'));
        $this->assertSame(2, $this->usage()['utterances']);

        // Her recording is deleted when the call ends, and only hers.
        $this->assertFileExists("{$session->directory}/555-1.wav");
        await($session->stop());
        $this->assertSame(["{$session->directory}/666-2.wav"], glob("{$session->directory}/*.wav"));
        $this->assertWavDuration(2.0, "{$session->directory}/666-2.wav");
        $this->assertSame([], $this->loggedProblems());
    }

    public function testStillOptsOutOfCallsInProgressWhenItCannotBeSaved(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Sounds good.']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->breakStatsDatabase();

        $this->optOut();

        // Nobody is told they opted out when that would be forgotten after this call.
        $this->assertSame(
            [['content' => 'I couldn\'t save that you opted out, so it only counts for the calls I am recording right now. Try again later.', 'ephemeral' => true]],
            $this->responses,
        );
        $this->assertSame([['user' => '555']], $this->logged('Could not save an opt-out: Database connection [stats] not configured.'));

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->runFor(1.5);

        $this->assertSame('', $this->transcript($session));
        $this->assertSame([], glob("{$session->directory}/*.wav"));
    }

    private function optOut(): void
    {
        (new OptoutCommand($this->discord))->handle($this->interaction($this->voiceChannel()));
    }
}
