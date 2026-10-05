<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Voice\Retention;
use App\Voice\VoiceSession;
use DateTimeImmutable;

use function React\Async\await;

final class VoiceRetentionTest extends VoiceTestCase
{
    public function testKeepsTheFolderOfACallInProgress(): void
    {
        $retention = new Retention($this->recordings, 7, $this->discord->getLogger());
        // A month from now, every call there is today is an old one.
        $later = new DateTimeImmutable('+30 days');
        $finished = sprintf('%s/%s/%s', $this->recordings, self::GUILD_ID, date('Y-m-d_H-i-s', time() - 3600));
        mkdir($finished, 0755, true);

        $session = VoiceSession::start($this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        // Another server's call that started at the same second isn't in progress.
        $otherServers = "{$this->recordings}/999/" . basename($session->directory);
        mkdir($otherServers, 0755, true);

        $this->assertSame(2, $retention->prune($later));

        $this->assertDirectoryExists($session->directory);
        $this->assertDirectoryDoesNotExist($finished);
        $this->assertDirectoryDoesNotExist($otherServers);

        // Once the call is over, its folder is deleted like any other, and the server's folder with it.
        await($session->stop());

        $this->assertSame(1, $retention->prune($later));

        $this->assertDirectoryDoesNotExist(dirname($session->directory));
        // What the tests keep next to the recordings is not a server's folder.
        $this->assertDirectoryExists("{$this->recordings}/models");
        $this->assertSame([['calls' => 2, 'days' => 7], ['calls' => 1, 'days' => 7]], $this->logged('Deleted old recordings'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testKeepsTheFolderOfACallThatIsStillBeingSummarized(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Sounds good.']);
        $retention = new Retention($this->recordings, 7, $this->discord->getLogger());
        $later = new DateTimeImmutable('+30 days');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->transcript($session) !== '', 'the transcript');

        // The call has stopped, and Claude is writing its summary, which is saved in the call's folder.
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '1']);
        $ended = $session->stop();

        $this->assertSame(0, $retention->prune($later));
        $this->assertDirectoryExists($session->directory);

        await($ended);
        $this->assertFileExists("{$session->directory}/summary.md");
        $this->assertSame(1, $retention->prune($later));
        $this->assertSame([], $this->loggedProblems());
    }
}
