<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Voice\VoiceSession;
use Discord\Voice\VoiceClient;
use PHPUnit\Framework\Attributes\DataProvider;
use React\EventLoop\LoopInterface;
use Tests\Fixtures\ManualTimers;

/**
 * What someone says is over once they have been silent for a moment: how long that is, and how often it is checked.
 *
 * The bot's timers only run out when a test says so, so each test decides when the bot looks for silence.
 */
final class VoicePauseTest extends VoiceTestCase
{
    private ManualTimers $timers;

    /** When Alice said her last word. */
    private float $spokeAt;

    protected function setUp(): void
    {
        parent::setUp();
        // Nobody talks to Claude here.
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => "Let's get lunch after this."]);
    }

    protected function loop(): LoopInterface
    {
        return $this->timers ??= new ManualTimers();
    }

    public function testWhatSomeoneSaysIsOverAfterAShortSilence(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // The bot looks for silence twenty times a second, so that it notices it as soon as it is long enough.
        $this->assertSame([0.05], $this->timers->pending());

        $this->aliceSpeaks($vc);
        $this->assertFalse($this->overAfter(0.3, pause: 0.6), 'Alice may only have paused.');

        // After 0.6 seconds, she is done: the bot no longer waits a whole second.
        $this->assertTrue($this->overAfter(0.65, pause: 0.6));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testVoicePauseSecondsDecidesWhenWhatSomeoneSaysIsOver(): void
    {
        // For people who pause longer in the middle of a sentence.
        $this->setEnv(['VOICE_PAUSE_SECONDS' => '1.6']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->aliceSpeaks($vc);
        $this->assertFalse($this->overAfter(0.8, pause: 1.6), 'Alice is thinking.');
        $this->assertTrue($this->overAfter(1.65, pause: 1.6));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAnEmptyVoicePauseSecondsIsTheDefault(): void
    {
        $this->setEnv(['VOICE_PAUSE_SECONDS' => '']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->aliceSpeaks($vc);
        $this->assertFalse($this->overAfter(0.3, pause: 0.6));
        $this->assertTrue($this->overAfter(0.65, pause: 0.6));
        $this->assertSame([], $this->loggedProblems());
    }

    #[DataProvider('pausesThatAreNone')]
    public function testSaysSoWhenVoicePauseSecondsIsNotAPause(string $pause): void
    {
        $this->setEnv(['VOICE_PAUSE_SECONDS' => $pause]);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // It is logged when the call starts, and the call goes on with the default.
        $this->assertSame(
            ['VOICE_PAUSE_SECONDS is not a number of seconds, 0.1 or more: what someone says ends after 0.6 s of silence.'],
            $this->loggedProblems(),
        );

        $this->aliceSpeaks($vc);
        $this->assertFalse($this->overAfter(0.3, pause: 0.6), 'Not after no pause at all.');
        $this->assertTrue($this->overAfter(0.65, pause: 0.6));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pausesThatAreNone(): iterable
    {
        yield 'not a number' => ['soon'];
        yield 'no time at all' => ['0'];
        yield 'less than any pause between two words' => ['0.05'];
        yield 'negative' => ['-1'];
    }

    public function testTheShortestPauseIsATenthOfASecond(): void
    {
        $this->setEnv(['VOICE_PAUSE_SECONDS' => '0.1']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->aliceSpeaks($vc);

        $this->assertTrue($this->overAfter(0.15, pause: 0.1));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testLogsHowLongAliceStoppedInTheMiddleOfWhatSheSaid(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // She stops for 0.3 s, shorter than the pause that ends what she says, and goes on.
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 0.6);
        $this->runFor(0.3);
        $this->aliceSpeaks($vc);
        $this->assertTrue($this->overAfter(0.65, pause: 0.6));

        $this->assertCount(1, $this->logged('Utterance ended'), 'It is one utterance.');
        $gaps = $this->logged('Utterance gaps');
        $this->assertCount(1, $gaps);
        $this->assertSame(1, $gaps[0]['long_gaps']);
        // Counted in milliseconds, and the time the test ran for is not exact.
        $this->assertGreaterThanOrEqual(290, $gaps[0]['longest_ms']);
        $this->assertLessThan(5000, $gaps[0]['longest_ms']);
        $this->assertSame([], $this->loggedProblems());
    }

    private function aliceSpeaks(VoiceClient $vc): void
    {
        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        // The clock the bot counts the silence with: the wall clock of a machine steps, and the monotonic one runs at its own pace.
        $this->spokeAt = hrtime(true) / 1e9;
    }

    /**
     * Has the bot look for silence once Alice has been silent for that long.
     *
     * @param float $pause The pause the call is expected to wait for.
     * @return bool Whether the bot then took what she said to be over. A machine that is busy can take far
     *              longer than it was asked to: when the pause is over although it shouldn't be yet, the answer
     *              is that it isn't, as nothing can be told from it.
     */
    private function overAfter(float $seconds, float $pause): bool
    {
        $this->runFor(max(0.0, $seconds - (hrtime(true) / 1e9 - $this->spokeAt)));
        $silent = hrtime(true) / 1e9 - $this->spokeAt;
        $this->timers->elapse(0.05);

        return $this->logged('Utterance ended') !== [] && ($seconds >= $pause || $silent < $pause - 0.1);
    }
}
