<?php

declare(strict_types=1);

namespace Tests\Live;

use App\Application;
use App\Support\Shell;
use App\Voice\Transcriber;
use App\Voice\VoiceSession;
use Discord\Discord;
use Discord\Voice\VoiceClient;
use Discord\WebSockets\Intents;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

use function React\Async\await;
use function React\Promise\race;

/**
 * Asks the bot a question in a real Discord voice call.
 *
 * A second bot (tests/Live/speaker.php) plays a spoken question in the test server's voice
 * channel and records what it hears back. Discord, its end-to-end encryption, whisper.cpp and
 * Piper are all real; only Claude is replaced by a fixed answer, so no Claude subscription is
 * used. .github/workflows/live-voice.yml sets everything up.
 */
final class VoiceRoundTripTest extends TestCase
{
    private const array REQUIRED_ENV = [
        'DISCORD_TEST_BOT_TOKEN',
        'DISCORD_TEST_SPEAKER_TOKEN',
        'DISCORD_TEST_VOICE_CHANNEL_ID',
        'LIVE_TEST_QUESTION',
    ];

    public function testAnswersAQuestionAskedInAVoiceCall(): void
    {
        foreach (self::REQUIRED_ENV as $name) {
            if ((string) getenv($name) === '') {
                $this->markTestSkipped("{$name} is not set; see .github/workflows/live-voice.yml.");
            }
        }

        $logs = new TestHandler();
        $joined = new Deferred();
        $app = new Application(
            [
                'token' => getenv('DISCORD_TEST_BOT_TOKEN'),
                'intents' => Intents::getDefaultIntents() | Intents::GUILD_MEMBERS,
                'loadAllMembers' => true,
                'logger' => new Logger('bot', [$logs, new StreamHandler('php://stderr', Level::Info)]),
            ],
            function (Discord $discord) use ($joined) {
                $channel = $discord->getChannel(getenv('DISCORD_TEST_VOICE_CHANNEL_ID'));

                if ($channel === null) {
                    $joined->reject(new RuntimeException('The voice channel was not found.'));

                    return;
                }

                $discord->joinVoiceChannel($channel, mute: false, deaf: false)->then(
                    fn (VoiceClient $vc) => $joined->resolve(VoiceSession::start($vc, $channel, $discord)),
                    fn (Throwable $e) => $joined->reject($e),
                );
            },
        );

        try {
            $session = $this->within(60, $joined->promise(), 'the bot to join the voice channel');

            $speakerRecordings = "{$session->directory}/speaker";
            mkdir($speakerRecordings, 0755, true);
            $speaker = json_decode($this->within(
                150,
                Shell::run(
                    // Its result is printed on stdout, so PHP's own messages must not end up there.
                    [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/speaker.php', getenv('LIVE_TEST_QUESTION'), $speakerRecordings],
                    timeout: 140,
                ),
                'the speaker to ask its question and hear the answer',
            ), true);
        } finally {
            if (isset($session)) {
                $session->stop();
            }

            $app->discord->close(false);
        }

        $logged = array_map(fn ($record) => $record->message, $logs->getRecords());
        $transcriptPath = "{$session->directory}/transcript.txt";
        $transcript = is_file($transcriptPath) ? file_get_contents($transcriptPath) : '';

        // The slash commands were registered with Discord on startup.
        $this->assertNotEmpty(preg_grep('/^Command record (has been saved|already exists)\.$/', $logged), 'The /record command was registered.');

        // The bot heard the question through Discord, and whisper understood it.
        $this->assertMatchesRegularExpression('/: .*what time is it/i', $transcript);

        // It answered, in the text chat and out loud.
        $this->assertStringContainsString('Claude: It is a quarter past four.', $transcript);
        $this->assertEmpty(preg_grep('/^(Voice reply failed|Could not post)/', $logged), 'Answering did not fail.');

        // The speaker heard the spoken answer, and whisper understands it too.
        $this->assertIsArray($speaker, 'The speaker reported what it heard.');
        $this->assertTrue($speaker['answered'], 'The speaker heard an answer.');
        $this->assertCount(1, $speaker['recordings'], 'Only the bot spoke to the speaker.');
        $heard = await(Transcriber::fromEnv()->transcribe($speaker['recordings'][0]));
        $this->assertStringContainsStringIgnoringCase('quarter', $heard, "The speaker heard: {$heard}");
    }

    /**
     * Waits for a promise, failing after the timeout.
     */
    private function within(float $seconds, PromiseInterface $promise, string $what): mixed
    {
        $timeout = new Deferred();
        $timer = Loop::addTimer($seconds, fn () => $timeout->reject(new RuntimeException("Timed out after {$seconds}s waiting for {$what}.")));

        try {
            return await(race([$promise, $timeout->promise()]));
        } finally {
            Loop::cancelTimer($timer);
        }
    }
}
