<?php

declare(strict_types=1);

/*
 * The other side of the live voice test: a second bot that joins the voice channel, asks a
 * spoken question, and records everything it hears until the call goes quiet.
 *
 * Usage: php tests/Live/speaker.php <question.wav> <output directory>
 * Needs DISCORD_TEST_SPEAKER_TOKEN and DISCORD_TEST_VOICE_CHANNEL_ID.
 * Writes {"recordings": [...], "answered": bool, "heardFrames": int, "sentPackets": int} to <output>/result.json
 * when done, and logs to <output>/speaker.log. Not to stdout: libdave prints its own logs there.
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

$logger = new Logger('speaker', [
    new StreamHandler('php://stderr', Level::Info),
    new StreamHandler("{$output}/speaker.log", Level::Debug),
]);
$discord = new Discord([
    'token' => getenv('DISCORD_TEST_SPEAKER_TOKEN'),
    'logger' => $logger,
]);

$fail = function (Throwable $e) use ($discord): never {
    fwrite(STDERR, 'Speaker failed: ' . $e->getMessage() . PHP_EOL);
    $discord->close();

    exit(1);
};

$discord->on('init', function (Discord $discord) use ($question, $output, $fail, $logger) {
    $channel = $discord->getChannel(getenv('DISCORD_TEST_VOICE_CHANNEL_ID'));

    if ($channel === null) {
        $fail(new RuntimeException('The voice channel was not found.'));
    }

    $discord->joinVoiceChannel($channel, mute: false, deaf: false)->then(
        function (VoiceClient $vc) use ($discord, $question, $output, $fail, $logger) {
            $vc->record(RecordingFormat::WAV, fn (string $userId) => "{$output}/{$userId}.wav");

            $heardAt = null;
            $heardFrames = 0;
            $vc->on('channel-pcm', function () use (&$heardAt, &$heardFrames) {
                $heardAt = microtime(true);
                $heardFrames++;
            });
            $sentPackets = 0;
            $vc->on('packet-sent', function () use (&$sentPackets) {
                $sentPackets++;
            });

            Loop::addTimer(SETTLE_SECONDS, function () use ($vc, $discord, $question, $output, $fail, $logger, &$heardAt, &$heardFrames, &$sentPackets) {
                $logger->info('Playing the question', ['file' => $question]);

                $vc->playFile($question)->then(function () use ($vc, $discord, $output, $logger, &$heardAt, &$heardFrames, &$sentPackets) {
                    $logger->info('Finished playing the question; listening for the answer', ['sent packets' => $sentPackets]);
                    $listeningSince = microtime(true);
                    $heardAt = null;

                    Loop::addPeriodicTimer(0.25, function (TimerInterface $timer) use ($vc, $discord, $output, $listeningSince, &$heardAt, &$heardFrames, &$sentPackets) {
                        $now = microtime(true);
                        $answered = $heardAt !== null && $now - $heardAt >= QUIET_SECONDS;

                        if (! $answered && $now - $listeningSince < MAX_LISTEN_SECONDS) {
                            return;
                        }

                        Loop::cancelTimer($timer);
                        $vc->stopRecording();
                        file_put_contents("{$output}/result.json", json_encode([
                            'recordings' => glob("{$output}/*.wav"),
                            'answered' => $answered,
                            'heardFrames' => $heardFrames,
                            'sentPackets' => $sentPackets,
                        ]));
                        $vc->close();
                        $discord->close();
                    });
                }, $fail);
            });
        },
        $fail,
    );
});

$discord->run();
