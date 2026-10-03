<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Commands\StopGlobalCommand;
use App\Voice\VoiceSession;

final class StopCommandTest extends CommandTestCase
{
    public function testStopsTheRecordingAndLeaves(): void
    {
        $channel = $this->voiceChannel();
        $session = VoiceSession::start($this->voiceClient($channel, connected: true), $channel, $this->discord);

        (new StopGlobalCommand($this->discord))->handle($this->interaction($channel));

        $this->assertSame([['content' => "⏹️ Stopped recording. Saved to `{$session->directory}`.", 'ephemeral' => false]], $this->responses);
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
    }

    public function testSaysSoWhenNotRecording(): void
    {
        (new StopGlobalCommand($this->discord))->handle($this->interaction($this->voiceChannel()));

        $this->assertSame([['content' => 'I am not recording in this server.', 'ephemeral' => true]], $this->responses);
    }
}
