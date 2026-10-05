<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Commands\Global\ForgetCommand;
use App\Voice\VoiceSession;

use function React\Async\await;

/**
 * /forget while a call is going on: what was said in it must not be remembered afterwards.
 */
final class ForgetInCallTest extends CommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT_MEMORY' => $this->claudeSays('- Updated by Claude.')]);
    }

    public function testForgetWithSomeoneStopsTheCallFromRememberingWhatTheTwoOfThemSaid(): void
    {
        $this->inCall('555', '666');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, my PIN is 1234.');

        (new ForgetCommand($this->discord))->handle($this->interaction(null, users: ['with' => '666']));
        $this->ask($vc, '555', 'Hey Claude, I am learning to sail.');
        await($session->stop());

        $this->assertSame([['content' => "I don't remember anything about you and Bob together, so there is nothing to forget.", 'ephemeral' => true]], $this->responses);
        $update = $this->untimed($this->claudeCalls()[3]['prompt']);
        $this->assertStringNotContainsString('1234', $update);
        $this->assertStringContainsString('Alice: Hey Claude, I am learning to sail.', $update);
        $this->assertSame('- Updated by Claude.', $this->memory()->read(['555', '666']));
    }

    public function testForgetStopsTheCallFromRememberingWhatWasSaidAloneWithTheBot(): void
    {
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->ask($vc, '555', 'Hey Claude, my PIN is 1234.');

        (new ForgetCommand($this->discord))->handle($this->interaction(null));
        await($session->stop());

        $this->assertCount(2, $this->claudeCalls(), 'The answer and the summary: there is nothing to remember.');
        $this->assertFileDoesNotExist("{$this->memories}/555.md");
        $this->assertSame([], $this->logged('Updated memory'));
    }
}
