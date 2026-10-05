<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Commands\Global\StopCommand;
use App\Voice\VoiceSession;

final class StopCommandTest extends CommandTestCase
{
    public function testStopsTheRecordingAndLeaves(): void
    {
        $channel = $this->voiceChannel();
        $session = VoiceSession::start($this->voiceClient($channel, connected: true), $channel, $this->discord);
        $this->waitUntil(fn () => count($this->waitingClaudes()) === 1 && count($this->pipers()) === 1, 'Claude Code and Piper to be started');

        (new StopCommand($this->discord))->handle($this->interaction($channel));

        $this->assertSame([['content' => "⏹️ Stopped recording. Saved to `{$session->directory}`.", 'ephemeral' => false]], $this->responses);
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));

        // The programs the call kept running, for its next question and its next sentence, are ended with it.
        $this->waitUntil(
            fn () => ! $this->isRunning($this->waitingClaudes()[0]) && ! $this->isRunning($this->pipers()[0]),
            'Claude Code and Piper to end',
        );
        $this->assertSame([1, 1], [count($this->waitingClaudes()), count($this->pipers())]);
    }

    public function testSaysSoWhenNotRecording(): void
    {
        (new StopCommand($this->discord))->handle($this->interaction($this->voiceChannel()));

        $this->assertSame([['content' => 'I am not recording in this server.', 'ephemeral' => true]], $this->responses);
    }
}
