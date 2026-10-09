<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Settings\UserSettings;
use App\Voice\VoiceSession;
use Discord\Voice\VoiceClient;
use PHPUnit\Framework\Attributes\DataProvider;

use function React\Async\await;

/**
 * While someone pauses, and whisper has the text of what they said so far, Claude is asked about it when
 * it is a sentence the bot would answer. Its answer is held: it is used once the pause is over and the prompt
 * built then is the one that was sent, and otherwise thrown away.
 *
 * The whisper server and Claude Code are the fixtures. The question goes out 0.3 s before the sentence is over,
 * which is as long as these tests have to look at what happened before.
 */
final class VoiceEarlyClaudeTest extends VoiceTestCase
{
    private const string QUESTION = 'Hey Claude, what time is it?';

    private const string ANSWER = 'It is a quarter past four.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv(['WHISPER_SERVER_BINARY' => dirname(__DIR__) . '/Fixtures/fake-whisper-server']);
        $this->setProcessEnv(['FAKE_WHISPER_SERVER_LOG' => "{$this->recordings}/whisper-server.log", 'FAKE_WHISPER_LOG' => "{$this->recordings}/whisper-cli.log"]);
    }

    public function testAsksClaudeWhileTheyPauseAndUsesTheAnswerOnceTheSentenceIsOver(): void
    {
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->logged('Asked Claude') !== [], 'Claude to be asked');

        // Nothing of it is out yet: the sentence is not over.
        $this->assertSame([], $this->logged('Utterance ended'));
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->played);
        $this->assertSame('', $this->transcript($session), 'Not even the transcript.');

        $this->waitUntil(fn () => count($this->sent) === 1, 'the answer');

        $this->assertSame(['> **Alice:** ' . self::QUESTION . "\n" . self::ANSWER], $this->sent);
        $this->assertCount(1, $this->claudeCalls(), 'Claude was asked once.');
        $this->assertSame([true], array_column($this->logged('Asked Claude'), 'early'));
        $this->assertNotNull($this->logged('Asked Claude')[0]['waited_ms'], 'A process was waiting for it.');
        $this->assertCount(1, $this->logged('Claude answered'));
        $this->assertSame(1, $this->usage()['answers'] ?? null);
        $this->assertSame([], $this->logged('Dropped an early question'));

        [$used] = $this->logged('Used the early question');
        $this->assertSame(['guild', 'session', 'user', 'ahead_ms'], array_keys($used));
        $this->assertSame('555', $used['user']);
        $this->assertGreaterThan(0, $used['ahead_ms']);
        $this->assertLessThan(5000, $used['ahead_ms']);

        // In order: asked, then the sentence is over, then what Claude wrote is used.
        $order = array_map(fn ($record) => $record->message, $this->logs->getRecords());
        $position = fn (string $message) => array_search($message, $order, true);
        $this->assertLessThan($position('Utterance ended'), $position('Asked Claude'));
        $this->assertLessThan($position('Used the early question'), $position('Utterance ended'));
        $this->assertLessThan($position('Claude started answering'), $position('Used the early question'));

        // The prompt is what it would have been after the sentence: the line is in the transcript, with its time.
        [$call] = $this->claudeCalls();
        $this->assertStringEndsWith($this->asking('Alice', self::QUESTION), $this->untimed($call['prompt']));
        $this->assertTrue($call['waited']);
        preg_match('/^(\[\d\d:\d\d:\d\d\] )Alice: ' . preg_quote(self::QUESTION, '/') . '$/m', $this->transcript($session), $line);
        $this->assertStringContainsString($line[1] . 'Alice: ' . self::QUESTION, $call['prompt'], 'The line has the time it was written with.');
        $this->assertCount(2, $this->waitingClaudes(), 'The one that was asked, and the one started for the next question: not a third when the answer is over.');

        $this->assertLogsNeverMention('quarter past', 'what time');
        $this->assertSame([], $this->loggedProblems());
        await($session->stop());
        $this->assertSame([], array_filter($this->waitingClaudes(), $this->isRunning(...)), 'No Claude Code is left running.');
    }

    public function testThrowsTheQuestionAwayWhenTheyGoOnTalkingAndAsksTheWholeSentence(): void
    {
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', 'Hey Claude, what time is it');
        $this->waitUntil(fn () => $this->logged('Asked Claude') !== [], 'Claude to be asked');
        $this->says($vc, '555', 'Hey Claude, what time is it in Lisbon?');
        $this->waitUntil(fn () => count($this->sent) === 1, 'the answer');

        $this->assertSame(['> **Alice:** Hey Claude, what time is it in Lisbon?' . "\n" . self::ANSWER], $this->sent);

        [$dropped] = $this->logged('Dropped an early question');
        $this->assertSame(['guild', 'session', 'user', 'after_ms', 'reason'], array_keys($dropped));
        $this->assertSame('they went on', $dropped['reason']);
        $this->assertCount(1, $this->logged('Dropped an early question'));
        $this->assertCount(1, $this->logged('Used the early question'), 'The whole sentence was asked early, once they paused again.');
        $this->assertCount(1, $this->logged('Claude answered'), 'One answer: the one that was thrown away is never counted.');
        $this->assertSame(1, $this->usage()['answers'] ?? null);
        $this->assertCount(1, $this->logged('Dropped an early transcription'));

        $calls = $this->claudeCalls();
        $this->assertCount(2, $calls);
        $this->assertStringNotContainsString('Lisbon', $calls[0]['prompt']);
        $this->assertStringContainsString('Lisbon', $calls[1]['prompt']);
        $this->assertFalse($this->isRunning($calls[0]['pid']), 'The process of the question that was thrown away is ended.');
        $this->assertStringNotContainsString('what time is it' . "\n", $this->transcript($session), 'The first part was never in the transcript.');
        $this->assertSame([], $this->loggedProblems());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function changes(): array
    {
        return [
            'a memory changed' => ['memory'],
            'someone joined' => ['joined'],
            'another line was said' => ['line'],
            'their privacy setting changed' => ['privacy'],
        ];
    }

    #[DataProvider('changes')]
    public function testAsksAgainWhenWhatWasAskedIsNotWhatWouldBeAskedNow(string $change): void
    {
        $this->memory()->save('555', '- Likes tea.');
        $settings = new UserSettings($this->discord->getLogger());
        // Their own memory is in what Claude is asked while they are alone with the bot, and with others when they said so.
        $settings->save('555', ['personal_memory_in_calls' => $change === 'privacy' ? UserSettings::WHEN_ASKED : UserSettings::AFTER_SHARE]);
        $this->inCall(...($change === 'privacy' ? ['555', '666'] : ['555']));
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', self::QUESTION);
        $this->assertStringContainsString('- Likes tea.', $this->waitForPrompt(0));

        match ($change) {
            'memory' => $this->memory()->save('555', '- Likes coffee.'),
            'joined' => $this->joins('666'),
            'line' => $this->saidEarlier($session, ['[10:00:00] Bob: Hello.']),
            'privacy' => $settings->save('555', ['personal_memory_in_calls' => UserSettings::AFTER_SHARE]),
        };

        $this->waitUntil(fn () => count($this->sent) === 1, 'the answer');

        $this->assertSame(['> **Alice:** ' . self::QUESTION . "\n" . self::ANSWER], $this->sent);
        $this->assertSame([true, false], array_column($this->logged('Asked Claude'), 'early'));
        $this->assertSame([], $this->logged('Used the early question'));
        $this->assertSame('what was asked changed', $this->logged('Dropped an early question')[0]['reason']);
        $this->assertCount(1, $this->logged('Claude answered'), 'The question that was thrown away is not an answer.');
        $this->assertSame(1, $this->usage()['answers'] ?? null);

        $calls = $this->claudeCalls();
        $this->assertCount(2, $calls);
        $this->assertFalse($this->isRunning($calls[0]['pid']));
        $this->assertNotSame($calls[0]['prompt'], $calls[1]['prompt']);
        $this->assertSame([], $this->loggedProblems());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function neverAnswered(): array
    {
        return [
            'the stop phrase' => ['Stop, Claude.'],
            'the leave phrase' => ['Disconnect, Claude.'],
            'only a call' => ['Hey Claude.'],
            'a sentence that does not name the bot' => ['Then we have an hour left.'],
        ];
    }

    public function testAsksAgainWhenTheSharedMemoryItWasMadeFromIsTakenBack(): void
    {
        $this->memory()->save('555', '- Likes tea.');
        (new UserSettings($this->discord->getLogger()))->save('555', ['personal_memory_in_calls' => UserSettings::AFTER_SHARE]);
        $this->inCall('555', '666');
        [$session, $vc] = $this->callWithServer();
        $session->share('555');

        $this->says($vc, '555', self::QUESTION);
        $this->assertStringContainsString('- Likes tea.', $this->waitForPrompt(0));

        $this->assertTrue($session->unshare('555'));
        $this->waitUntil(fn () => array_filter($this->sent, fn (string $message) => str_starts_with($message, '> **Alice:**')) !== [], 'the answer');

        $this->assertSame([true, false], array_column($this->logged('Asked Claude'), 'early'));
        $this->assertStringNotContainsString('- Likes tea.', $this->claudeCalls()[1]['prompt'], 'Their memory is not in what Claude was asked after all.');
        $this->assertSame('what was asked changed', $this->logged('Dropped an early question')[0]['reason']);
        $this->assertSame([], $this->logged('Used the early question'));
        $this->assertSame([], $this->loggedProblems());
    }

    #[DataProvider('neverAnswered')]
    public function testIsNotAskedAboutWhatWouldNotBeAnswered(string $text): void
    {
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', $text);
        $this->waitUntil(fn () => $this->logged('Transcribed') !== [], 'it to be transcribed');
        $this->runFor(0.3);

        $this->assertTrue($this->logged('Transcribed')[0]['early']);
        $this->assertSame([], $this->logged('Asked Claude'));
        $this->assertSame([], $this->logged('Dropped an early question'));
        $this->assertSame([], array_filter($this->sent, fn (string $message) => str_starts_with($message, '> **Alice:**')), 'Nothing was answered.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testEndsTheQuestionOfSomeoneWhoOptsOutAndSaysNothingAboutIt(): void
    {
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->logged('Asked Claude') !== [], 'Claude to be asked');
        VoiceSession::optOut('555');
        $this->waitUntil(fn () => $this->claudeCalls() !== [] && ! $this->isRunning($this->claudeCalls()[0]['pid']), 'Claude Code to end');
        $this->runFor(0.8);

        $this->assertSame([], $this->logged('Dropped an early question'), 'Nothing is said about someone who opted out.');
        $this->assertSame([], $this->logged('Used the early question'));
        $this->assertSame([], $this->logged('Claude answered'));
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->played);
        $this->assertSame('', $this->transcript($session));
        $this->assertCount(1, $this->claudeCalls(), 'Claude was not asked again.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testEndsTheQuestionWhenTheCallStopsAndDoesNotWaitForIt(): void
    {
        // Claude takes its time with the question: the summary of the call is not made to wait for it.
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '5']);
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', self::QUESTION);
        $this->waitForPrompt(0);
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0']);
        $started = hrtime(true);
        $stopped = $session->stop();
        $this->waitUntil(fn () => ! $this->isRunning($this->claudeCalls()[0]['pid']), 'Claude Code to end', 2.0);
        await($stopped);

        $this->assertLessThan(4.0, (hrtime(true) - $started) / 1e9, 'The call did not wait for the answer.');
        $this->assertSame('the call stopped', $this->logged('Dropped an early question')[0]['reason']);
        $this->assertSame([], $this->logged('Used the early question'));
        $this->assertSame([], $this->logged('Claude answered'));
        $this->assertSame([], array_filter($this->sent, fn (string $message) => str_starts_with($message, '> **Alice:**')), 'Nothing was answered.');
        $this->assertFalse($this->isRunning($this->claudeCalls()[0]['pid']));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testOnePersonsEarlyQuestionNeverMakesAnothersRealOneWait(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_SERVER_NUMBERED' => '1']);
        $this->inCall('555', '666');
        [$session, $vc] = $this->callWithServer();

        $this->speak($vc, ssrc: 555, userId: '555', seconds: 1.0);
        $this->runFor(0.1);
        $this->speak($vc, ssrc: 666, userId: '666', seconds: 1.0);
        $this->waitUntil(fn () => count($this->sent) === 2, 'both answers');

        // One early question at a time: Alice's, which was there first. Bob's is asked when his sentence is over,
        // of the process that was started when Alice's took the one that waited.
        $asked = $this->logged('Asked Claude');
        $this->assertSame([[true, '555'], [false, '666']], array_map(fn (array $context) => [$context['early'], $context['user']], $asked));
        $this->assertNotNull($asked[0]['waited_ms']);
        $this->assertNotNull($asked[1]['waited_ms'], 'Bob\'s question found a process waiting.');
        $this->assertCount(1, $this->logged('Used the early question'));
        $this->assertSame([], $this->logged('Dropped an early question'));
        $this->assertSame([], array_filter($this->logs->getRecords(), fn ($record) => $record->message === 'No Claude Code process was waiting for the question'));
        $this->assertCount(2, $this->logged('Claude answered'));
        $this->assertSame([], $this->loggedProblems());
        $this->assertSame(
            ['> **Alice:** Hey Claude, what time is it? 2' . "\n" . self::ANSWER, '> **Bob:** Hey Claude, what time is it? 3' . "\n" . self::ANSWER],
            $this->sent,
        );
    }

    public function testDoesNotJumpTheQueueWhileAnAnswerIsBeingWritten(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_PAUSE' => '10']);
        $this->inCall('555', '666');
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => count($this->logged('Used the early question')) === 1, 'the first question to be used');
        // Claude is still writing its answer to Alice: Bob's question waits for its turn, as it did.
        $this->says($vc, '666', 'Hey Claude, what day is it?');
        $this->waitUntil(fn () => count($this->logged('Transcribed')) === 2, 'what Bob said to be transcribed');
        $this->runFor(0.3);

        $this->assertSame([true], array_column($this->logged('Asked Claude'), 'early'));
        $this->assertCount(1, $this->claudeCalls());

        touch($this->claudeResume);
        $this->waitUntil(fn () => count($this->sent) === 2, 'both answers');

        $this->assertSame([true, false], array_column($this->logged('Asked Claude'), 'early'));
        $this->assertSame([], $this->logged('Dropped an early question'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAnswersAsBeforeWhenItCouldNotBeAskedEarly(): void
    {
        // Who is in the call can't be read while the question is built, and can be once the sentence is over.
        $this->voiceStates = $states = new class () extends \ArrayObject {
            public bool $broken = false;

            public function getIterator(): \Iterator
            {
                return $this->broken ? throw new \RuntimeException('The voice states are not there.') : parent::getIterator();
            }
        };
        $this->inCall('555');
        [$session, $vc] = $this->callWithServer();

        $states->broken = true;
        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->loggedProblems() !== [], 'the failure to be told');
        $states->broken = false;
        $this->waitUntil(fn () => count($this->sent) === 1, 'the answer');

        $this->assertSame(['Could not ask Claude early: The voice states are not there.'], $this->loggedProblems());
        $this->assertSame([false], array_column($this->logged('Asked Claude'), 'early'), 'It was asked once the sentence was over.');
        $this->assertSame([], $this->logged('Used the early question'));
        $this->assertSame([], $this->logged('Dropped an early question'));
        $this->assertSame(['> **Alice:** ' . self::QUESTION . "\n" . self::ANSWER], $this->sent);
    }

    public function testAHandedOffLookupStartsOnlyOnceTheSentenceIsOver(): void
    {
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream("Let me look into that.\n", 'LOOK UP: ', 'Find the time in Lisbon.'),
            'FAKE_CLAUDE_OUTPUT_LOOKUP' => self::claudeResult('It is a quarter past four in Lisbon.'),
        ]);
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->logged('Asked Claude') !== [], 'Claude to be asked');
        $this->waitUntil(fn () => $this->claudeCalls() !== [], 'Claude Code to have its prompt');
        $this->runFor(0.1);

        $this->assertSame([], $this->logged('Utterance ended'));
        $this->assertSame([], $this->lookups(), 'Nothing is looked up before the sentence is over.');
        $this->assertSame([], $this->sent);

        $this->waitUntil(fn () => $this->lookups() !== [], 'the lookup to start');

        $this->assertNotSame([], $this->logged('Utterance ended'));
        $this->assertSame(['> **Alice:** ' . self::QUESTION . "\nLet me look into that."], array_slice($this->sent, 0, 1));
        $this->assertSame([], $this->loggedProblems());
    }

    /**
     * A call whose whisper server is ready.
     *
     * @return array{VoiceSession, VoiceClient}
     */
    private function callWithServer(): array
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->waitUntil(fn () => $this->logged('Whisper server ready') !== [], 'the whisper server to be ready');

        return [$session, $vc];
    }

    /**
     * The prompt of a call to Claude, once it was given it.
     */
    private function waitForPrompt(int $call): string
    {
        $this->waitUntil(fn () => isset($this->claudeCalls()[$call]), 'Claude Code to have its prompt');

        return $this->claudeCalls()[$call]['prompt'];
    }

    /**
     * @return list<array{prompt: string, system: string}> The times Claude Code was run to look something up.
     */
    private function lookups(): array
    {
        return array_values(array_filter($this->claudeCalls(), fn (array $call) => str_starts_with($call['system'], 'You look things up')));
    }
}
