<?php

declare(strict_types=1);

/*
 * The other side of the live voice test: a second bot that joins the voice channel, asks a
 * spoken question, and records everything it hears until the call goes quiet.
 *
 * Usage: php tests/Live/speaker.php <question.wav> <output directory>
 * Needs DISCORD_TEST_SPEAKER_TOKEN and DISCORD_TEST_VOICE_CHANNEL_ID.
 * Prints {"recordings": [...], "answered": bool} when done.
 */

use Discord\Discord;
use Discord\Voice\Recording\RecordingFormat;
use Discord\Voice\VoiceClient;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $question, $output] = $argv;

/** Wait after joining, so Discord's end-to-end encryption includes everyone before speaking. */
const SETTLE_SECONDS = 5.0;

/** The answer is over once nothing was heard for this long. */
const QUIET_SECONDS = 3.0;

/** Stop listening after this long, answered or not. */
const MAX_LISTEN_SECONDS = 60.0;

$discord = new Discord([
    'token' => getenv('DISCORD_TEST_SPEAKER_TOKEN'),
    // stdout carries the result, so logs go to stderr.
    'logger' => new Logger('speaker', [new StreamHandler('php://stderr', Level::Info)]),
]);

$fail = function (Throwable $e) use ($discord): never {
    fwrite(STDERR, 'Speaker failed: ' . $e->getMessage() . PHP_EOL);
    $discord->close();

    exit(1);
};

$discord->on('init', function (Discord $discord) use ($question, $output, $fail) {
    $channel = $discord->getChannel(getenv('DISCORD_TEST_VOICE_CHANNEL_ID'));

    if ($channel === null) {
        $fail(new RuntimeException('The voice channel was not found.'));
    }

    $discord->joinVoiceChannel($channel, mute: false, deaf: false)->then(
        function (VoiceClient $vc) use ($discord, $question, $output, $fail) {
            $vc->record(RecordingFormat::WAV, fn (string $userId) => "{$output}/{$userId}.wav");

            $heardAt = null;
            $vc->on('channel-pcm', function () use (&$heardAt) {
                $heardAt = microtime(true);
            });

            Loop::addTimer(SETTLE_SECONDS, fn () => $vc->playFile($question)->then(
                function () use ($vc, $discord, $output, &$heardAt) {
                    $listeningSince = microtime(true);
                    $heardAt = null;

                    Loop::addPeriodicTimer(0.25, function (TimerInterface $timer) use ($vc, $discord, $output, $listeningSince, &$heardAt) {
                        $now = microtime(true);
                        $answered = $heardAt !== null && $now - $heardAt >= QUIET_SECONDS;

                        if (! $answered && $now - $listeningSince < MAX_LISTEN_SECONDS) {
                            return;
                        }

                        Loop::cancelTimer($timer);
                        $vc->stopRecording();
                        echo json_encode(['recordings' => glob("{$output}/*.wav"), 'answered' => $answered]), PHP_EOL;
                        $vc->close();
                        $discord->close();
                    });
                },
                $fail,
            ));
        },
        $fail,
    );
});

$discord->run();
