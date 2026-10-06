<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Voice\VoiceSession;

use function React\Async\await;

/**
 * A call keeps a whisper server running, so that what is said is transcribed without loading the model each time.
 *
 * The server and whisper-cli are stand-ins that log to files of their own, so that a test can tell which of them ran.
 */
final class VoiceWhisperServerTest extends VoiceTestCase
{
    private string $serverLog;

    private string $cliLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverLog = "{$this->recordings}/whisper-server.log";
        $this->cliLog = "{$this->recordings}/whisper-cli.log";
        $this->setEnv(['WHISPER_SERVER_BINARY' => dirname(__DIR__) . '/Fixtures/fake-whisper-server']);
        $this->setProcessEnv(['FAKE_WHISPER_SERVER_LOG' => $this->serverLog, 'FAKE_WHISPER_LOG' => $this->cliLog]);
    }

    public function testTranscribesWhatIsSaidWithTheServerOnceItHasLoadedItsModel(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->waitUntil(fn () => $this->logged('Whisper server ready') !== [], 'the whisper server to be ready');

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->assertSame(['> **Alice:** Hey Claude, what time is it?' . "\n" . 'It is a quarter past four.'], $this->sent);
        // The first request is the server's warm-up; the second is what Alice said, in the language of the server's settings.
        $requests = $this->serverRequests();
        $this->assertCount(2, $requests);
        $this->assertSame('request language=en prompt=- format=json filename=audio.wav bytes=32044 wav=16000Hz,1ch,16bit,32000bytes', $requests[0]);
        $this->assertMatchesRegularExpression('/^request language=auto prompt=- format=json filename=audio.wav bytes=\d{5,} wav=48000Hz,2ch,16bit,\d+bytes$/', $requests[1], 'The recording as the call wrote it.');
        $this->assertFileDoesNotExist($this->cliLog, 'whisper-cli did not run.');
        $this->assertSame([], $this->loggedProblems());
        $this->assertCount(1, $this->logged('Transcribed'));

        $ready = $this->logged('Whisper server ready')[0];
        $this->assertSame(['guild', 'session', 'ms', 'port'], array_keys($ready));
        $this->assertSame($this->logged('Voice session started')[0]['session'], $ready['session'], 'Said in the log of the call that started it.');

        // The call is over once the server has ended.
        $pid = $this->serverPids()[0];
        $this->assertTrue($this->isRunning($pid));
        await($session->stop());
        $this->assertFalse($this->isRunning($pid), 'The server ends with the call.');
    }

    public function testWhatWasSaidBeforeTheCallEndedIsStillTranscribedByTheServer(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->waitUntil(fn () => $this->logged('Whisper server ready') !== [], 'the whisper server to be ready');

        // Alice's last words are with the server when the call ends: they are in the transcript, and in what the call remembers.
        $this->speakAndWait($vc, '555');
        $this->waitUntil(fn () => count($this->serverRequests()) === 2, 'the server to be asked');
        $stopped = $session->stop();
        $this->transcribe();
        await($stopped);

        $this->assertCount(1, $this->logged('Transcribed'));
        $this->assertFileDoesNotExist($this->cliLog, 'The server was there until it had answered.');
        $this->assertSame([], $this->loggedProblems());
        $this->assertStringContainsString('Alice: Hey Claude, what time is it?', $this->transcript($session));
    }

    public function testGivesWhatIsSaidForLongTimeToBeTranscribedForTheLengthOfIt(): void
    {
        // Longer than the 3 seconds that any request has, and shorter than the 5 that ten seconds of speech have.
        $this->setProcessEnv(['FAKE_WHISPER_DELAY' => '4']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->waitUntil(fn () => $this->logged('Whisper server ready') !== [], 'the whisper server to be ready');

        $this->speak($vc, ssrc: 555, userId: '555', seconds: 10.0);
        $this->waitUntil(fn () => $this->sent !== [], 'the answer', 20.0);

        $this->assertFileDoesNotExist($this->cliLog, 'The server had the time it needed.');
        $this->assertSame([], $this->loggedProblems());
        $this->assertGreaterThanOrEqual(4000, $this->logged('Transcribed')[0]['ms']);
    }

    public function testWhisperCliTranscribesWhatIsSaidWhileTheServerLoads(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_SERVER_LOAD' => '30']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->assertCount(1, $this->sent, 'Alice is answered, the server or not.');
        $this->assertFileExists($this->cliLog, 'whisper-cli transcribed it.');
        $this->assertSame([], $this->logged('Whisper server ready'));
        $this->assertSame([], $this->loggedProblems());

        // A server that is still loading when the call is over is ended with it.
        $pid = $this->serverPids()[0];
        await($session->stop());
        $this->assertFalse($this->isRunning($pid));
    }

    public function testWhisperCliTranscribesWhenTheServerCannotBeStarted(): void
    {
        $this->setEnv(['WHISPER_SERVER_BINARY' => "{$this->recordings}/no-such-whisper-server"]);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->waitUntil(fn () => $this->loggedProblems() !== [], 'the failure to be told');

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->assertCount(1, $this->sent);
        $this->assertFileExists($this->cliLog);
        $this->assertCount(1, $this->loggedProblems());
        $this->assertStringStartsWith('The whisper server stopped (setpriv exited with code', $this->loggedProblems()[0]);
    }

    public function testWhisperCliTranscribesWhenTheServerFailsInTheMiddleOfTheCall(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->waitUntil(fn () => $this->logged('Whisper server ready') !== [], 'the whisper server to be ready');
        $this->setProcessEnv(['FAKE_WHISPER_SERVER_STATUS' => '500']);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->assertCount(1, $this->sent, 'What Alice said is not lost with the server.');
        $this->assertFileExists($this->cliLog);
        $this->assertSame(['The whisper server could not transcribe, whisper-cli does: HTTP status code 500 (Fake)'], $this->loggedProblems());
    }

    public function testTwoCallsShareOneServerAndTheLastToEndEndsIt(): void
    {
        $first = VoiceSession::start($this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $second = VoiceSession::start($secondVc = $this->voiceClient($secondChannel = $this->voiceChannel('201', '101')), $secondChannel, $this->discord);
        $this->waitUntil(fn () => $this->logged('Whisper server ready') !== [], 'the whisper server to be ready');

        $this->assertCount(1, $this->serverPids(), 'One server for both calls.');
        $pid = $this->serverPids()[0];

        await($first->stop());
        $this->assertTrue($this->isRunning($pid), 'The other call still needs it.');

        // The second call is answered by the server that the first one started.
        $this->ask($secondVc, '555', 'Hey Claude, what time is it?');
        $this->assertCount(2, $this->serverRequests(), 'Its warm-up, and what Alice said.');
        $this->assertCount(1, $this->serverPids());
        $this->assertFileDoesNotExist($this->cliLog);

        await($second->stop());
        $this->assertFalse($this->isRunning($pid));
    }

    /**
     * @return list<string> What the server was asked, the warm-up first.
     */
    private function serverRequests(): array
    {
        return array_values(array_filter(
            explode("\n", trim(file_get_contents($this->serverLog))),
            fn (string $line) => str_starts_with($line, 'request '),
        ));
    }

    /**
     * @return list<int> The process ID of each server that was started.
     */
    private function serverPids(): array
    {
        preg_match_all('/^pid=(\d+)$/m', file_get_contents($this->serverLog), $found);

        return array_map(intval(...), $found[1]);
    }
}
