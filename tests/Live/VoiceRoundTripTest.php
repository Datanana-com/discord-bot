<?php

declare(strict_types=1);

namespace Tests\Live;

use App\Analytics\Usage;
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
use Tests\UsesStatsDatabase;
use Throwable;

use function React\Async\await;
use function React\Async\delay;
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
    use UsesStatsDatabase;

    /** What Claude's stand-in hands off in the second question, and what the lookup finds. */
    private const string LOOKING = 'Let me look into that.';

    private const string FOUND = 'The next meeting is at six.';

    private const array REQUIRED_ENV = [
        'DISCORD_TEST_BOT_TOKEN',
        'DISCORD_TEST_SPEAKER_TOKEN',
        'DISCORD_TEST_VOICE_CHANNEL_ID',
        'LIVE_TEST_QUESTION',
        'FAKE_ENV',
    ];

    public function testAnswersAQuestionAskedInAVoiceCall(): void
    {
        foreach (self::REQUIRED_ENV as $name) {
            if ((string) getenv($name) === '') {
                $this->markTestSkipped("{$name} is not set; see .github/workflows/live-voice.yml.");
            }
        }

        $this->useStatsDatabase();
        $logs = new TestHandler();
        $logger = new Logger('bot', [
            $logs,
            new StreamHandler('php://stderr', Level::Info),
            // Kept with the recordings, for when a run needs investigating.
            new StreamHandler((getenv('RECORDINGS_PATH') ?: 'recordings') . '/bot.log', Level::Debug),
        ]);
        $joined = new Deferred();
        $voiceClient = null;
        $app = new Application(
            [
                'token' => getenv('DISCORD_TEST_BOT_TOKEN'),
                'intents' => Intents::getDefaultIntents() | Intents::GUILD_MEMBERS,
                'loadAllMembers' => true,
                'logger' => $logger,
            ],
            function (Discord $discord) use ($joined, &$voiceClient) {
                $channel = $discord->getChannel(getenv('DISCORD_TEST_VOICE_CHANNEL_ID'));

                if ($channel === null) {
                    $joined->reject(new RuntimeException('The voice channel was not found.'));

                    return;
                }

                $discord->joinVoiceChannel($channel, mute: false, deaf: false)->then(
                    function (VoiceClient $vc) use ($joined, $channel, $discord, &$voiceClient) {
                        $voiceClient = $vc;
                        $joined->resolve(VoiceSession::start($vc, $channel, $discord));
                    },
                    fn (Throwable $e) => $joined->reject($e),
                );
            },
        );

        try {
            $session = $this->within(60, $joined->promise(), 'the bot to join the voice channel');

            // Counts what reaches the bot: UDP datagrams, those that decrypted into audio, and
            // speaking events, which tell it who sends which audio.
            $received = ['udp packets' => 0, 'audio packets' => 0, 'speaking events' => 0];
            $voiceClient->udp->on('message', function () use (&$received) {
                $received['udp packets']++;
            });
            $voiceClient->on('raw', function () use (&$received) {
                $received['audio packets']++;
            });
            $voiceClient->on('speaking', function () use (&$received) {
                $received['speaking events']++;
            });

            $speakerRecordings = "{$session->directory}/speaker";
            mkdir($speakerRecordings, 0755, true);
            $this->within(
                150,
                Shell::run([PHP_BINARY, __DIR__ . '/speaker.php', getenv('LIVE_TEST_QUESTION'), $speakerRecordings], timeout: 140),
                'the speaker to ask its question and hear the answer',
            );
            $result = "{$speakerRecordings}/result.json";
            $speaker = is_file($result) ? json_decode(file_get_contents($result), true) : null;

            // What the first question made, before the second one adds to it.
            $firstSentences = count(glob("{$session->directory}/claude-*.ogg"));
            $firstSpeech = count(array_filter($logs->getRecords(), fn ($record) => $record->message === 'Started speaking'));

            // The same question again, which Claude's stand-in now hands off to be looked up, as Claude does when
            // it can't answer at once: through the real call, the lookup runs and what it finds is posted and told.
            // The Claude Code process that waits for a question was started before, so it reads its answer again
            // from this file; the process that looks something up is started now, with this environment.
            file_put_contents(getenv('FAKE_ENV'), 'FAKE_CLAUDE_OUTPUT=' . escapeshellarg(self::handOff()) . "\n");
            putenv('FAKE_CLAUDE_OUTPUT_LOOKUP=' . json_encode(['type' => 'result', 'is_error' => false, 'result' => self::FOUND]));
            $second = "{$session->directory}/speaker-2";
            mkdir($second, 0755, true);
            $this->within(150, Shell::run([PHP_BINARY, __DIR__ . '/speaker.php', getenv('LIVE_TEST_QUESTION'), $second], timeout: 140), 'the speaker to ask its second question');
            $transcriptPath = "{$session->directory}/transcript.txt";
            $this->waitUntil(
                fn () => str_contains((string) @file_get_contents($transcriptPath), 'Looked up for') && substr_count((string) @file_get_contents($transcriptPath), 'Claude: ' . self::LOOKING) >= 2,
                60,
                'what was looked up to be told',
            );
        } finally {
            putenv('FAKE_CLAUDE_OUTPUT_LOOKUP');

            try {
                if (isset($session)) {
                    $logger->info('The bot received', $received ?? []);
                    // The call's summary is posted after it stopped, while the bot is still connected to Discord.
                    $this->within(60, $session->stop(), 'the call to be summarized');
                }
            } finally {
                $app->discord->close(false);
            }
        }

        $logged = array_map(fn ($record) => $record->message, $logs->getRecords());
        $transcriptPath = "{$session->directory}/transcript.txt";
        $transcript = is_file($transcriptPath) ? file_get_contents($transcriptPath) : '';

        // The slash commands were registered with Discord on startup. Discord refuses a command
        // whose options it doesn't accept, which nothing but the real Discord can tell.
        $this->assertNotEmpty(preg_grep('/^Command record (has been saved|already exists)\.$/', $logged), 'The /record command was registered.');
        $this->assertNotEmpty(preg_grep('/^Command settings (has been saved|already exists)\.$/', $logged), 'The /settings command was registered, with its options.');
        $this->assertNotEmpty(preg_grep('/^Command recall (has been saved|already exists)\.$/', $logged), 'The /recall command was registered, with its required question.');
        $this->assertNotEmpty(preg_grep('/^Command meet (has been saved|already exists)\.$/', $logged), 'The /meet command was registered, with the people it takes.');
        $this->assertEmpty(preg_grep('/^Could not (save command|fetch the registered commands)/', $logged), 'Discord accepted every command.');
        // The workflow doesn't set BOT_REMOVE_OLD_COMMANDS, so nothing is removed, and certainly not what the bot just registered.
        $this->assertEmpty(preg_grep('/^(Command .+ has been removed\.|Could not remove command)/', $logged), 'No command was removed.');

        // The bot heard the question through Discord, and whisper understood it.
        $this->assertMatchesRegularExpression(
            '/: .*what time is it/i',
            $transcript,
            'The bot received ' . json_encode($received) . '; the speaker reported ' . json_encode($speaker) . '.',
        );

        // It answered, in the text chat and out loud: one sentence after the other, each from its own file.
        $this->assertStringContainsString('Claude: It is a quarter past four. The meeting starts at five.', $transcript);
        $this->assertEmpty(preg_grep('/^(Voice reply failed|Could not post)/', $logged), 'Answering did not fail.');
        $this->assertSame(2, $firstSentences, 'Each sentence was synthesized on its own.');
        $this->assertSame(1, $firstSpeech, 'The bot started speaking the answer.');

        // The call's whisper server, built next to whisper-cli, loaded its model and did not fail: whisper-cli transcribes without a word
        // when there is none, or it isn't ready, and nothing else here would tell.
        $this->assertContains('Whisper server ready', $logged, 'The call started its whisper server.');
        $this->assertEmpty(preg_grep('/^The whisper server/', $logged), 'The whisper server did not fail.');

        // The question was answered by the Claude Code process that waited for it, and spoken by the Piper the call
        // started with, the real one: neither had to be started for it.
        $this->assertEmpty(
            preg_grep('/^(No Claude Code process was waiting|The waiting Claude Code process did not answer|Piper had stopped)/', $logged),
            'The programs the call keeps running were there for the answer.',
        );

        // When the call ended, it was summarized. Claude's stand-in gives the summary the same text as the answer.
        $this->assertContains('Summarized the call', $logged);
        $this->assertStringEqualsFile("{$session->directory}/summary.md", "It is a quarter past four. The meeting starts at five.\n");
        $this->assertEmpty(preg_grep('/^Could not summarize/', $logged), 'Summarizing did not fail.');

        // What was handed off was looked up in the background, through the real call, and what it found was posted and told.
        $this->assertMatchesRegularExpression('/Looked up for .+: ' . preg_quote(self::FOUND, '/') . '/', $transcript);
        $this->assertCount(1, array_keys($logged, 'Looking something up', true), 'Telling what was found hands nothing off again.');
        $this->assertCount(1, array_keys($logged, 'Looked something up', true));
        $this->assertEmpty(preg_grep('/^Could not look something up/', $logged), 'Looking something up did not fail.');

        // The call, its two answers (a question that is handed off counts once) and the lookup were counted for /stats.
        $usage = (new Usage($logger))->summary((string) $voiceClient->channel->guild_id);
        $this->assertSame([1, 2, 1, 0], [$usage['calls'], $usage['answers'], $usage['lookups'], $usage['failures']], 'Usage: ' . json_encode($usage));

        // The speaker heard the spoken answer, and whisper understands it too.
        $this->assertIsArray($speaker, 'The speaker reported what it heard.');
        $this->assertTrue($speaker['answered'], 'The speaker heard an answer.');
        $this->assertCount(1, $speaker['recordings'], 'Only the bot spoke to the speaker.');
        $heard = await(Transcriber::fromEnv()->transcribe($speaker['recordings'][0]));
        $this->assertMatchesRegularExpression('/quarter.+meeting/is', $heard, "The speaker heard both sentences, in order: {$heard}");
        // From the first word: the bot sends the first packet of an answer 40 ms after it says it speaks, and nothing of the
        // start may be lost on the way. Piper's file begins with about 80 ms of near-silence before "It", so this bounds
        // what was lost at about 80 ms, not at the 40 ms.
        $this->assertMatchesRegularExpression('/^\W*(it is|it\'s) a quarter/i', $heard, "The speaker heard the answer from its first word: {$heard}");
    }

    /**
     * What Claude's stand-in prints for an answer that hands a question off to be looked up.
     */
    private static function handOff(): string
    {
        $text = fn (string $text) => json_encode(['type' => 'stream_event', 'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => $text]]]);
        $task = 'When is the next meeting?';

        return implode("\n", [
            $text(self::LOOKING . "\n"),
            $text("LOOK UP: {$task}"),
            json_encode(['type' => 'result', 'is_error' => false, 'result' => self::LOOKING . "\nLOOK UP: {$task}"]),
        ]);
    }

    /**
     * Waits until something is true, checked a few times a second, failing after the timeout.
     *
     * @param callable(): bool $condition
     */
    private function waitUntil(callable $condition, float $seconds, string $what): void
    {
        $deadline = microtime(true) + $seconds;

        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Timed out after {$seconds}s waiting for {$what}.");
            }

            delay(0.25);
        }
    }

    protected function tearDown(): void
    {
        // await() runs the loop to completion when PHP exits, and Discord's client leaves
        // timers on it, so without this the test process would never exit.
        Loop::stop();
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
