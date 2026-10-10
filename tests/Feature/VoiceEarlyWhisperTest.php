<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Voice\UtteranceSplitter;
use App\Voice\VoiceSession;
use ReflectionProperty;

use function React\Async\await;

/**
 * While someone pauses, the whisper server is given what they said so far, so that the text is there when the
 * pause turns out to be the end of the sentence. If they go on, none of it is used.
 *
 * The server stands in with a log of the requests it got (the first is its warm-up). {@see testHandsWhisperWhatWasSaidSoFarWhileTheyPause()}
 * shows when the request comes: after 0.3 s of silence, 0.3 s before the sentence is over.
 */
final class VoiceEarlyWhisperTest extends VoiceTestCase
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

    public function testHandsWhisperWhatWasSaidSoFarWhileTheyPause(): void
    {
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', 'Hey Claude, what time is it?');
        $this->waitUntil(fn () => count($this->serverRequests()) === 2, 'whisper to be given what she said');

        $this->assertSame([], $this->logged('Utterance ended'), 'She is still being waited for.');
        // It names the bot, so Claude is asked while it waits (see VoiceEarlyClaudeTest): nothing else happens early.
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->played);
        $this->assertSame('', $this->transcript($session), 'Not even the transcript.');

        $this->waitUntil(fn () => count($this->sent) === 1, 'the answer');

        $this->assertCount(2, $this->serverRequests(), 'The sentence did not ask whisper again.');
        $this->assertFileDoesNotExist($this->cliLog);
        $this->assertMatchesRegularExpression('/^request language=auto prompt=- format=json filename=audio.wav bytes=\d{5,} wav=48000Hz,2ch,16bit,\d+bytes$/', $this->serverRequests()[1], 'A whole recording, with a header that says how long it is.');
        $this->assertCount(1, $this->claudeCalls());
        $this->assertSame([], $this->loggedProblems());

        [$transcribed] = $this->logged('Transcribed');
        $this->assertSame(['guild', 'session', 'user', 'ms', 'characters', 'early'], array_keys($transcribed));
        $this->assertTrue($transcribed['early']);
        $this->assertSame(strlen('Hey Claude, what time is it?'), $transcribed['characters']);
        $this->assertLessThan(300, $transcribed['ms'], 'What was left of the wait for the text: whisper had it before the sentence was over.');
        $this->assertSame([], $this->logged('Dropped an early transcription'));
        $this->assertSame([], glob("{$session->directory}/utterances/*"), 'Neither the copy nor the recording is left.');
    }

    public function testTheTextIsTheFirstAnswerOfWhisperAndWhisperIsNotAskedAgain(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_SERVER_NUMBERED' => '1']);
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', 'Sounds good.');
        $this->waitUntil(fn () => $this->logged('Transcribed') !== [], 'it to be transcribed');
        $this->runFor(0.3);

        // Request 1 was the warm-up, and request 2 the early start: a third would have said "Sounds good. 3".
        $this->assertCount(2, $this->serverRequests());
        $this->assertSame("Alice: Sounds good. 2\n", $this->untimed($this->transcript($session)));
    }

    public function testTheSentenceWaitsForTheTextWhenWhisperIsStillHearingItWhenTheSentenceIsOver(): void
    {
        [$session, $vc] = $this->callWithServer();

        $this->speakAndWait($vc, '555');
        $this->waitUntil(fn () => count($this->serverRequests()) === 2, 'whisper to be given what she said');
        $this->runFor(0.3);
        $this->assertSame([], $this->logged('Transcribed'), 'Whisper has not answered.');

        $this->transcribe();
        $this->waitUntil(fn () => $this->logged('Transcribed') !== [], 'it to be transcribed');

        $this->assertCount(2, $this->serverRequests());
        [$transcribed] = $this->logged('Transcribed');
        $this->assertTrue($transcribed['early']);
        $this->assertGreaterThanOrEqual(250, $transcribed['ms'], 'It counts from the end of the sentence: this is what the sentence waited for.');
        $this->assertStringContainsString('Alice: Hey Claude, what time is it?', $this->transcript($session));
    }

    public function testWhatTheyWentOnToSayIsAllThatIsHeardAndTheFirstPartIsNeverUsed(): void
    {
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', 'Secret first half.');
        $this->waitUntil(fn () => count($this->serverRequests()) === 2, 'whisper to be given the first half');
        $this->says($vc, '555', 'Secret second half.');
        $this->waitUntil(fn () => $this->logged('Transcribed') !== [], 'it to be transcribed');

        $dropped = $this->logged('Dropped an early transcription');
        $this->assertCount(1, $dropped);
        $this->assertSame(['guild', 'session', 'user', 'after_ms'], array_keys($dropped[0]));
        $this->assertSame('555', $dropped[0]['user']);
        $this->assertGreaterThan(0, $dropped[0]['after_ms'], 'How long whisper had had it.');
        $this->assertLessThan(5000, $dropped[0]['after_ms']);
        $this->assertCount(3, $this->serverRequests(), 'The warm-up, the first half and the second half; the sentence did not ask again.');
        $this->assertSame("Alice: Secret second half.\n", $this->untimed($this->transcript($session)));
        $this->assertCount(1, $this->logged('Utterance ended'));
        $this->assertLogsNeverMention('Secret');
        $this->assertSame([], glob("{$session->directory}/utterances/*"));
    }

    public function testTranscribesTheSentenceAsUsualWhenWhisperCouldNotHearTheEarlyStart(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_SERVER_NUMBERED' => '1']);
        [$session, $vc] = $this->callWithServer();

        $this->setProcessEnv(['FAKE_WHISPER_SERVER_STATUS' => '500']);
        $this->says($vc, '555', 'Sounds good.');
        $this->waitUntil(fn () => count($this->serverRequests()) === 2, 'whisper to be given what she said');
        $this->setProcessEnv(['FAKE_WHISPER_SERVER_STATUS' => '']);
        $this->waitUntil(fn () => $this->logged('Transcribed') !== [], 'it to be transcribed');

        $this->assertCount(3, $this->serverRequests(), 'The sentence asked again.');
        $this->assertFalse($this->logged('Transcribed')[0]['early']);
        $this->assertSame("Alice: Sounds good. 3\n", $this->untimed($this->transcript($session)));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testNothingOfWhatSomeoneWhoOptedOutSaidIsUsedOrKept(): void
    {
        [$session, $vc] = $this->callWithServer();

        touch($this->whisperHold);
        $this->says($vc, '555', 'Secret words.');
        $this->waitUntil(fn () => count($this->serverRequests()) === 2, 'whisper to be given what she said');

        VoiceSession::optOut('555');
        $this->assertSame([], glob("{$session->directory}/utterances/early-*"), 'The copy is gone at once.');
        $this->transcribe();
        $this->runFor(0.8);

        $this->assertCount(2, $this->serverRequests());
        $this->assertSame([], $this->logged('Transcribed'));
        $this->assertSame([], $this->logged('Utterance ended'));
        $this->assertSame([], $this->logged('Dropped an early transcription'), 'Nothing is said about someone who opted out.');
        $this->assertSame('', $this->transcript($session));
        $this->assertSame([], $this->claudeCalls());
        $this->assertLogsNeverMention('Secret');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testNothingIsGivenToWhisperForSomeoneWhoOptedOutWhilePausing(): void
    {
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', 'Secret words.');
        // The copy is made at 0.3 s, and the optOut comes before it.
        VoiceSession::optOut('555');
        $this->runFor(0.9);

        $this->assertCount(1, $this->serverRequests(), 'Only the warm-up.');
        $this->assertSame([], glob("{$session->directory}/utterances/*"));
        $this->assertSame('', $this->transcript($session));
    }

    public function testTheCopyOfSomeoneWhoOptsOutAfterTheirSentenceEndedIsNotSentEither(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Sounds good.']);
        [$session, $vc] = $this->callWithServer();

        // Bob's request is with the server, held, and Alice's copy waits behind it.
        touch($this->whisperHold);
        $this->speak($vc, ssrc: 666, userId: '666', seconds: 1.0);
        $this->waitUntil(fn () => count($this->serverRequests()) === 2, 'whisper to be given what Bob said');
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->logged('Utterance ended')) === 2, 'both sentences to be over');
        $this->assertCount(2, $this->serverRequests());

        VoiceSession::optOut('555');
        $this->assertFileExists("{$session->directory}/utterances/early-1.wav", 'Bob\'s copy is with the server.');
        $this->assertFileDoesNotExist("{$session->directory}/utterances/early-2.wav", 'Her copy is gone, though its sentence is over.');
        $this->transcribe();
        $this->waitUntil(fn () => $this->logged('Transcribed') !== [], 'Bob to be transcribed');
        $this->runFor(0.5);

        $this->assertCount(2, $this->serverRequests(), 'Her copy was never sent.');
        $this->assertSame(['666'], array_column($this->logged('Transcribed'), 'user'));
        $this->assertSame("Bob: Sounds good.\n", $this->untimed($this->transcript($session)));
        $this->assertSame([], glob("{$session->directory}/utterances/*"));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testOptingOutAfterTheCopyWasHeardHasNothingLeftToDelete(): void
    {
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', 'Sounds good.');
        $this->waitUntil(fn () => $this->logged('Transcribed') !== [], 'it to be transcribed');

        // The copy was deleted when whisper had heard it: deleting it again would be a PHP warning, which fails the run.
        VoiceSession::optOut('555');

        $this->assertSame([], glob("{$session->directory}/utterances/*"));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testTheCallEndsWithTheSentenceInProgressTranscribedFromTheEarlyText(): void
    {
        [$session, $vc] = $this->callWithServer();

        touch($this->whisperHold);
        $this->says($vc, '555', 'Sounds good.');
        $this->waitUntil(fn () => count($this->serverRequests()) === 2, 'whisper to be given what she said');
        $stopped = $session->stop();
        $this->transcribe();
        await($stopped);

        $this->assertCount(2, $this->serverRequests(), 'The sentence did not ask again.');
        $this->assertTrue($this->logged('Transcribed')[0]['early']);
        $this->assertStringContainsString('Alice: Sounds good.', $this->transcript($session));
        $this->assertSame([], glob("{$session->directory}/utterances/*"));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testTheServerIsNotEndedWhileItIsStillHearingWhatSomeoneWhoOptedOutSaid(): void
    {
        [$session, $vc] = $this->callWithServer();
        $pid = $this->serverPids()[0];

        touch($this->whisperHold);
        $this->says($vc, '555', 'Secret words.');
        $this->waitUntil(fn () => count($this->serverRequests()) === 2, 'whisper to be given what she said');
        VoiceSession::optOut('555');
        $stopped = $session->stop();
        $this->runFor(0.5);

        $this->assertTrue($this->isRunning($pid), 'It ends once it has answered.');

        $this->transcribe();
        await($stopped);

        $this->assertFalse($this->isRunning($pid));
        $this->assertSame([], $this->loggedProblems());
        $this->assertSame([], glob("{$session->directory}/utterances/*"));
    }

    public function testTwoPeopleAreHeardOneAtATimeAndEachIsGivenTheirOwnText(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_SERVER_NUMBERED' => '1', 'FAKE_WHISPER_OUTPUT' => 'Sounds good.']);
        [$session, $vc] = $this->callWithServer();

        touch($this->whisperHold);
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->speak($vc, ssrc: 666, userId: '666', seconds: 1.0);
        $this->waitUntil(fn () => count($this->serverRequests()) === 2, 'whisper to be given what Alice said');
        $this->runFor(0.4);

        // What Bob said waits for what Alice said: a request the server gets to late counts as one it didn't answer in time.
        $this->assertCount(2, $this->serverRequests(), 'The warm-up, and Alice.');

        $this->transcribe();
        $this->waitUntil(fn () => count($this->logged('Transcribed')) === 2, 'both to be transcribed');

        $this->assertCount(3, $this->serverRequests(), 'Nobody was asked again.');
        $this->assertSame(['555', '666'], array_column($this->logged('Transcribed'), 'user'));
        $this->assertSame([true, true], array_column($this->logged('Transcribed'), 'early'));
        $this->assertSame("Alice: Sounds good. 2\nBob: Sounds good. 3\n", $this->untimed($this->transcript($session)));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testACopyThatWaitsForWhisperIsNeverSentWhenTheyGoOn(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_SERVER_NUMBERED' => '1', 'FAKE_WHISPER_OUTPUT' => 'Sounds good.']);
        [$session, $vc] = $this->callWithServer();

        touch($this->whisperHold);
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->serverRequests()) === 2, 'whisper to be given what Alice said');
        // Bob pauses while whisper is busy with Alice: his copy waits, and he goes on.
        $this->speak($vc, ssrc: 666, userId: '666', seconds: 1.0);
        $this->runFor(0.4);
        $this->assertCount(2, $this->serverRequests());
        $this->speak($vc, ssrc: 666, userId: '666', seconds: 1.0);
        $this->assertCount(1, $this->logged('Dropped an early transcription'));

        $this->transcribe();
        $this->waitUntil(fn () => count($this->logged('Transcribed')) === 2, 'both to be transcribed');

        // Bob's first copy would have been request 3, and what the sentence is made of request 4.
        $this->assertCount(3, $this->serverRequests(), 'The warm-up, Alice, and Bob once.');
        $this->assertSame("Alice: Sounds good. 2\nBob: Sounds good. 3\n", $this->untimed($this->transcript($session)));
    }

    public function testTheTextOfOneSentenceIsNeverTheTextOfTheNext(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_SERVER_NUMBERED' => '1', 'FAKE_WHISPER_OUTPUT' => 'Sounds good.']);
        [$session, $vc] = $this->callWithServer();

        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => count($this->logged('Transcribed')) === 1, 'the first sentence to be transcribed');

        // 30 seconds are cut off at once, without a pause to copy: they are whisper's third answer, not the first one again.
        // What is left over is too short to be kept.
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 30.2);
        $this->waitUntil(fn () => count($this->logged('Transcribed')) === 2, 'the cut sentence to be transcribed');

        $this->assertFalse($this->logged('Transcribed')[1]['early']);
        $this->assertStringStartsWith("Alice: Sounds good. 2\nAlice: Sounds good. 3\n", $this->untimed($this->transcript($session)));
    }

    public function testWhatWhisperHeardBeforeSheWentOnIsNotTheTextOfTheLongSentenceShePausedIn(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_SERVER_NUMBERED' => '1', 'FAKE_WHISPER_OUTPUT' => 'Sounds good.']);
        [$session, $vc] = $this->callWithServer();

        $this->speak($vc, ssrc: 555, userId: '555', seconds: 29.0);
        $this->waitUntil(fn () => count($this->serverRequests()) === 2, 'whisper to be given the first 29 seconds');
        // Whisper has answered, and the copy is deleted: she goes on within the 0.3 s that are left of the pause.
        $this->waitUntil(fn () => glob("{$session->directory}/utterances/early-*") === [], 'whisper to have answered');
        // She goes on, to the end of the 30 seconds that are cut off at once: they are not the 29 whisper heard, though it is back with them.
        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.2);
        $this->waitUntil(fn () => $this->logged('Transcribed') !== [], 'the 30 seconds to be transcribed');

        $this->assertCount(1, $this->logged('Dropped an early transcription'));
        $this->assertFalse($this->logged('Transcribed')[0]['early']);
        $this->assertStringStartsWith("Alice: Sounds good. 3\n", $this->untimed($this->transcript($session)));
    }

    public function testNothingIsCopiedWithoutAServer(): void
    {
        $this->setEnv(['WHISPER_SERVER_BINARY' => 'false']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->assertFileExists($this->cliLog, 'whisper-cli transcribed it.');
        $this->assertFalse($this->logged('Transcribed')[0]['early']);
        $this->assertSame(0, $this->copiesMade($session), 'Nothing was even copied.');
        $this->assertSame([], glob("{$session->directory}/utterances/*"));
    }

    public function testTranscribesTheSentenceWithWhisperCliWhenTheServerCannotBeStarted(): void
    {
        $this->setEnv(['WHISPER_SERVER_BINARY' => "{$this->recordings}/no-such-whisper-server"]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->waitUntil(fn () => $this->loggedProblems() !== [], 'the failure to be told');

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->assertFileExists($this->cliLog, 'whisper-cli transcribed it.');
        $this->assertFalse($this->logged('Transcribed')[0]['early']);
        $this->assertSame([], $this->logged('Dropped an early transcription'));
        $this->assertSame(0, $this->copiesMade($session), 'There was never a server to hear a copy.');
        $this->assertSame([], glob("{$session->directory}/utterances/*"));
    }

    public function testNothingIsGivenToWhisperCliWhileTheServerLoads(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_SERVER_LOAD' => '30']);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, what time is it?');

        $this->assertFalse($this->logged('Transcribed')[0]['early']);
        $this->assertSame(0, $this->copiesMade($session), 'Nothing is copied to be thrown away while the server loads.');
        $this->assertFileExists($this->cliLog, 'whisper-cli transcribed it.');
        $this->assertSame([], glob("{$session->directory}/utterances/*"));
        await($session->stop());
    }

    /**
     * A call whose whisper server is ready.
     *
     * @return array{VoiceSession, \Discord\Voice\VoiceClient}
     */
    private function callWithServer(): array
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->waitUntil(fn () => $this->logged('Whisper server ready') !== [], 'the whisper server to be ready');

        return [$session, $vc];
    }

    /**
     * @return int How many copies of what was said so far the call has made, to give to whisper.
     */
    private function copiesMade(VoiceSession $session): int
    {
        return (new ReflectionProperty(UtteranceSplitter::class, 'copies'))->getValue((new ReflectionProperty(VoiceSession::class, 'splitter'))->getValue($session));
    }

    /**
     * @return list<string> What the server was asked, one line for each request.
     */
    private function serverRequests(): array
    {
        return array_values(array_filter(
            explode("\n", trim((string) file_get_contents($this->serverLog))),
            fn (string $line) => str_starts_with($line, 'request '),
        ));
    }

    /**
     * @return list<int> The process ID of each server that was started.
     */
    private function serverPids(): array
    {
        preg_match_all('/^pid=(\d+)$/m', (string) file_get_contents($this->serverLog), $found);

        return array_map(intval(...), $found[1]);
    }
}
