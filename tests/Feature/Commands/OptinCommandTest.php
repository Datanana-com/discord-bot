<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Commands\Global\OptinCommand;
use App\Privacy\OptOuts;
use App\Voice\VoiceSession;

use function React\Async\await;

final class OptinCommandTest extends CommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Alice and Bob opted out earlier.
        (new OptOuts())->add('555');
        (new OptOuts())->add('666');
    }

    public function testOptsWhoeverUsedItBackIn(): void
    {
        $this->optIn();

        $this->assertSame(['666'], (new OptOuts())->all(), 'Bob is still opted out.');
        $this->assertSame([[
            'content' => 'You opted back in: I record, transcribe and answer you again, in every server.'
                . ' If I am recording a call you are in, I transcribe and answer you from now on, but only record you again once you rejoin it.'
                . ' Use /optout to undo this.',
            'ephemeral' => true,
        ]], $this->responses);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testSaysSoWhenNotOptedOut(): void
    {
        (new OptOuts())->remove('555');

        $this->optIn();

        $this->assertSame(['666'], (new OptOuts())->all());
        $this->assertSame(
            [['content' => 'You hadn\'t opted out, so nothing changed: I record, transcribe and answer you. Use /optout to stop that.', 'ephemeral' => true]],
            $this->responses,
        );
    }

    public function testIsAnsweredAgainInACallInProgressAndRecordedAfterRejoining(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->runFor(1.5);
        $this->assertSame('', $this->transcript($session));

        $this->optIn();

        // Alice is transcribed and answered again, and Bob still isn't.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');
        $this->assertSame(["> **Alice:** Hey Claude, what time is it?\nIt is a quarter past four."], $this->sent);
        $this->assertStringNotContainsString('Bob', $this->transcript($session));

        // The voice client was told to write nothing of her when she first spoke: only after she
        // leaves and rejoins, which gives her a new stream, is she recorded.
        $this->assertSame([], glob("{$session->directory}/*.wav"));
        $this->speak($vc, ssrc: 3, userId: '555', seconds: 2.0);
        $this->waitUntil(fn () => count($this->played) === 2, 'the second answer to be spoken');
        await($session->stop());

        $this->assertSame(["{$session->directory}/555-2.wav"], glob("{$session->directory}/*.wav"));
        $this->assertWavDuration(2.0, "{$session->directory}/555-2.wav");
        $this->assertSame(['555'], array_column($this->logged('Recording a speaker'), 'user'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testDeletesWhatWasRecordedWhileOptedOutEvenAfterOptingBackIn(): void
    {
        (new OptOuts())->remove('555');
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Sounds good.']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Alice opts out during the call and changes her mind: her recording so far has what she said in between.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        VoiceSession::optOut('555');
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->optIn();
        await($session->stop());

        $this->assertSame([], glob("{$session->directory}/*.wav"));
    }

    public function testStaysOptedOutWhenThatCannotBeSaved(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->breakStatsDatabase();

        $this->optIn();

        $this->assertSame(
            [['content' => 'I couldn\'t opt you back in right now, so you are still opted out. Try again later.', 'ephemeral' => true]],
            $this->responses,
        );
        $this->assertSame([['user' => '555']], $this->logged('Could not remove an opt-out: Database connection [stats] not configured.'));

        // The call in progress goes on without her as well.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->runFor(1.5);

        $this->assertSame('', $this->transcript($session));
    }

    private function optIn(): void
    {
        (new OptinCommand($this->discord))->handle($this->interaction($this->voiceChannel()));
    }
}
