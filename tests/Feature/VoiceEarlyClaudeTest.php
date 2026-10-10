<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Settings\UserSettings;
use App\Voice\VoiceSession;
use Closure;
use Discord\Voice\VoiceClient;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use React\EventLoop\Loop;

use function React\Async\await;

/**
 * While someone pauses, and whisper has the text of what they said so far, Claude is asked about it when
 * it is a sentence the bot would answer. Its answer is held: it is used once the pause is over and the prompt
 * built then is the one that was sent, and otherwise thrown away.
 *
 * The whisper server and Claude Code are the fixtures. The question goes out 0.3 s before the sentence is over,
 * which is too short for a test that polls: what a test does inside that time is done by the log line that
 * says Claude was asked ({@see afterAsked()}), one tick after it, and what it looks at when the sentence ends is
 * taken by the log line that says it did ({@see atEnd()}).
 */
final class VoiceEarlyClaudeTest extends VoiceTestCase
{
    private const string QUESTION = 'Hey Claude, what time is it?';

    private const string ANSWER = 'It is a quarter past four.';

    /** @var list<array{string, Closure(): void, bool, bool}> The log line, what to do, whether on the next tick, and whether it was done. */
    private array $hooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv(['WHISPER_SERVER_BINARY' => dirname(__DIR__) . '/Fixtures/fake-whisper-server']);
        $this->setProcessEnv(['FAKE_WHISPER_SERVER_LOG' => "{$this->recordings}/whisper-server.log", 'FAKE_WHISPER_LOG' => "{$this->recordings}/whisper-cli.log"]);

        $this->discord->getLogger()->pushHandler(new class (function (LogRecord $record): void {
            foreach ($this->hooks as $number => [$message, $do, $deferred, $done]) {
                if ($done || $record->message !== $message) {
                    continue;
                }

                $this->hooks[$number][3] = true;
                $deferred ? Loop::futureTick($do) : $do();
            }
        }) extends AbstractProcessingHandler {
            public function __construct(private readonly Closure $hook)
            {
                parent::__construct();
            }

            protected function write(LogRecord $record): void
            {
                ($this->hook)($record);
            }
        });
    }

    public function testAsksClaudeWhileTheyPauseAndUsesTheAnswerOnceTheSentenceIsOver(): void
    {
        [$session, $vc] = $this->callWithServer();
        $atEnd = [];
        $this->atEnd(function () use (&$atEnd, $session): void {
            $atEnd = [
                'asked' => $this->logged('Asked Claude'),
                'sent' => $this->sent,
                'played' => $this->played,
                'started' => $this->logged('Claude started answering'),
                'answered' => $this->logged('Claude answered'),
                'transcript' => $this->transcript($session),
            ];
        });

        // The second changes between Claude being asked and the end of the sentence: the line is written with the
        // time it was asked, or the two prompts differ.
        $fraction = microtime(true) - floor(microtime(true));
        usleep((int) (fmod(0.5 - $fraction + 1, 1) * 1e6));
        $before = date('H:i:s');
        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => count($this->sent) === 1, 'the answer');
        $after = date('H:i:s');

        // When the sentence was over, Claude had been asked, and nothing of the answer was out: not even the transcript.
        $this->assertSame([true], array_column($atEnd['asked'], 'early'));
        $this->assertSame([], $atEnd['sent']);
        $this->assertSame([], $atEnd['played']);
        $this->assertSame([], $atEnd['started']);
        $this->assertSame([], $atEnd['answered']);
        $this->assertSame('', $atEnd['transcript']);

        $this->assertSame(['> **Alice:** ' . self::QUESTION . "\n" . self::ANSWER], $this->sent);
        $this->assertCount(1, $this->claudeCalls(), 'Claude was asked once.');
        $this->assertNotNull($this->logged('Asked Claude')[0]['waited_ms'], 'A process was waiting for it.');
        $this->assertCount(1, $this->logged('Claude answered'));
        $this->assertSame(1, $this->usage()['answers'] ?? null);
        $this->assertSame([], $this->logged('Dropped an early question'));

        [$used] = $this->logged('Used the early question');
        $this->assertSame(['guild', 'session', 'user', 'ahead_ms'], array_keys($used));
        $this->assertSame('555', $used['user']);
        $this->assertGreaterThanOrEqual(250, $used['ahead_ms'], 'It was asked before the pause was over, 0.3 s of it.');
        $this->assertLessThan(3000, $used['ahead_ms']);

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
        $this->assertGreaterThanOrEqual($before, trim($line[1], '[] '), 'And that is the time it was asked.');
        $this->assertLessThanOrEqual($after, trim($line[1], '[] '));
        $this->assertSame([], $this->openQuestions($session), 'A question that was used is not kept.');
        $this->assertCount(2, $this->waitingClaudes(), 'The one that was asked, and the one started for the next question: not a third when the answer is over.');

        $this->assertLogsNeverMention('quarter past', 'what time');
        $this->assertSame([], $this->loggedProblems());
        await($session->stop());
        $this->assertSame([], array_filter($this->waitingClaudes(), $this->isRunning(...)), 'No Claude Code is left running.');
    }

    public function testThrowsTheQuestionAwayWhenTheyGoOnTalkingAndAsksTheWholeSentence(): void
    {
        [$session, $vc] = $this->callWithServer();
        // They go on a moment after Claude was asked.
        $this->afterAsked(fn () => $this->says($vc, '555', 'Hey Claude, what time is it in Lisbon?'));

        $this->says($vc, '555', 'Hey Claude, what time is it');
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

        $this->assertSame([true, true], array_column($this->logged('Asked Claude'), 'early'));
        $calls = $this->claudeCalls();
        $this->assertStringContainsString('Lisbon', end($calls)['prompt']);
        $this->assertFalse($this->isRunning($this->waitingClaudes()[0]), 'The process of the question that was thrown away is ended.');
        $this->assertSame([], $this->openQuestions($session), 'Nor is one that was thrown away.');
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

        $this->afterAsked(fn () => match ($change) {
            'memory' => $this->memory()->save('555', '- Likes coffee.'),
            'joined' => $this->joins('666'),
            'line' => $this->saidEarlier($session, ['[10:00:00] Bob: Hello.']),
            'privacy' => $settings->save('555', ['personal_memory_in_calls' => UserSettings::AFTER_SHARE]),
        });

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => count($this->sent) === 1, 'the answer');

        $this->assertSame(['> **Alice:** ' . self::QUESTION . "\n" . self::ANSWER], $this->sent);
        $this->assertSame([true, false], array_column($this->logged('Asked Claude'), 'early'));
        $this->assertSame([], $this->logged('Used the early question'));
        $this->assertSame('what was asked changed', $this->logged('Dropped an early question')[0]['reason']);
        $this->assertCount(1, $this->logged('Claude answered'), 'The question that was thrown away is not an answer.');
        $this->assertSame(1, $this->usage()['answers'] ?? null);

        $calls = $this->claudeCalls();
        $this->assertCount(2, $calls);
        $this->assertStringContainsString('- Likes tea.', $calls[0]['prompt']);
        $this->assertFalse($this->isRunning($calls[0]['pid']));
        $this->assertNotSame($calls[0]['prompt'], $calls[1]['prompt']);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAsksAgainWhenWhatWasSaidBeforeWasForgottenMeanwhile(): void
    {
        $this->inCall('555');
        [$session, $vc] = $this->callWithServer();
        $this->ask($vc, '555', 'Hey Claude, what is the capital of France?');
        $this->assertSame([], $this->logged('Dropped an early question'));

        $this->afterAsked(fn () => VoiceSession::forget('555'));
        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => count($this->sent) === 2, 'the second answer');

        $this->assertSame('what was asked changed', $this->logged('Dropped an early question')[0]['reason']);
        $calls = $this->claudeCalls();
        $this->assertCount(3, $calls);
        $this->assertStringContainsString('capital of France', $calls[1]['prompt'], 'Asked early with the call so far.');
        $this->assertStringNotContainsString('capital of France', $calls[2]['prompt'], 'What was forgotten is not in what Claude was asked after all.');
        $this->assertStringEndsWith($this->asking('Alice', self::QUESTION), $this->untimed($calls[2]['prompt']));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAsksAgainWhenTheSharedMemoryItWasMadeFromIsTakenBack(): void
    {
        $this->memory()->save('555', '- Likes tea.');
        (new UserSettings($this->discord->getLogger()))->save('555', ['personal_memory_in_calls' => UserSettings::AFTER_SHARE]);
        $this->inCall('555', '666');
        [$session, $vc] = $this->callWithServer();
        $session->share('555');
        $this->afterAsked(fn () => $session->unshare('555'));

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => array_filter($this->sent, fn (string $message) => str_starts_with($message, '> **Alice:**')) !== [], 'the answer');

        $this->assertSame([true, false], array_column($this->logged('Asked Claude'), 'early'));
        $this->assertStringContainsString('- Likes tea.', $this->claudeCalls()[0]['prompt']);
        $this->assertStringNotContainsString('- Likes tea.', $this->claudeCalls()[1]['prompt'], 'Their memory is not in what Claude was asked after all.');
        $this->assertSame('what was asked changed', $this->logged('Dropped an early question')[0]['reason']);
        $this->assertSame([], $this->logged('Used the early question'));
        $this->assertSame([], $this->loggedProblems());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function neverAnswered(): array
    {
        return [
            // More words than a call: it is the phrase that makes it nothing to answer.
            'the stop phrase' => ['Please stop Claude now.'],
            'the leave phrase' => ['Please disconnect Claude now.'],
            'only a call' => ['Hey Claude.'],
            'a sentence that does not name the bot' => ['Then we have an hour left.'],
        ];
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

    public function testAnEmptySentenceIsNotAskedAboutInAServerThatAnswersEverything(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => '']);
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', '');
        $this->waitUntil(fn () => $this->logged('Transcribed') !== [], 'it to be transcribed');
        $this->runFor(0.3);

        $this->assertSame([], $this->logged('Asked Claude'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testDoesNotAskEarlyWithTheMemoryOfSomeoneWhoAllowedItOnlyAfterSharing(): void
    {
        $this->memory()->save('555', '- Likes tea.');
        (new UserSettings($this->discord->getLogger()))->save('555', ['personal_memory_in_calls' => UserSettings::AFTER_SHARE]);
        $this->inCall('555', '666');
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => count($this->sent) === 1, 'the answer');

        $this->assertStringNotContainsString('- Likes tea.', $this->claudeCalls()[0]['prompt'], 'Not in a call with others, until they share it.');
        $this->assertSame([true], array_column($this->logged('Asked Claude'), 'early'));
        $this->assertCount(1, $this->logged('Used the early question'));
    }

    public function testSomeoneElseOptingOutLeavesAnotherPersonsQuestionAlone(): void
    {
        $this->inCall('666');
        [$session, $vc] = $this->callWithServer();
        $this->afterAsked(fn () => VoiceSession::optOut('555'));

        $this->says($vc, '666', self::QUESTION);
        $this->waitUntil(fn () => count($this->sent) === 1, 'the answer');

        $this->assertSame([true], array_column($this->logged('Asked Claude'), 'early'));
        $this->assertCount(1, $this->logged('Used the early question'));
        $this->assertSame([], $this->logged('Dropped an early question'));
    }

    public function testAsksAFollowUpOnceItIsOverWhenTheBotWasOnlyCalled(): void
    {
        [$session, $vc] = $this->callWithServer();

        $this->says($vc, '555', 'Hey Claude.');
        $this->waitUntil(fn () => $this->logged('Transcribed') !== [], 'the call to be transcribed');
        $this->says($vc, '555', 'What time is it?');
        $this->waitUntil(fn () => count($this->sent) === 1, 'the answer');

        // It doesn't name the bot: what makes it a question is the call before it, which is known once that is over.
        $this->assertSame([false], array_column($this->logged('Asked Claude'), 'early'));
        $this->assertSame([], $this->logged('Used the early question'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testEndsTheQuestionOfSomeoneWhoOptsOutAndSaysNothingAboutIt(): void
    {
        [$session, $vc] = $this->callWithServer();
        $this->afterAsked(fn () => VoiceSession::optOut('555'));

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->waitingClaudes() !== [] && ! $this->isRunning($this->waitingClaudes()[0]), 'Claude Code to end');
        $this->runFor(0.8);

        $this->assertSame([], $this->logged('Dropped an early question'), 'Nothing is said about someone who opted out.');
        $this->assertSame([], $this->logged('Used the early question'));
        $this->assertSame([], $this->logged('Claude answered'));
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->played);
        $this->assertSame('', $this->transcript($session));
        $this->assertCount(1, $this->logged('Asked Claude'), 'Claude was not asked again.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testEndsTheQuestionOfSomeoneWhoOptsOutAfterTheirSentenceEndedAndSaysNothingAboutIt(): void
    {
        [$session, $vc] = $this->callWithServer();
        // The sentence is over and has taken the question over: it waits for its turn.
        $this->hooks[] = ['Transcribed', fn () => VoiceSession::optOut('555'), false, false];

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->waitingClaudes() !== [] && ! $this->isRunning($this->waitingClaudes()[0]), 'Claude Code to end');
        $this->runFor(0.5);

        $this->assertSame([], $this->logged('Dropped an early question'), 'Nothing is said about someone who opted out.');
        $this->assertSame([], $this->logged('Used the early question'));
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testEndsTheQuestionWhenTheCallStopsAndDoesNotWaitForIt(): void
    {
        // Claude takes its time with the question: the summary of the call is not made to wait for it.
        $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '5']);
        [$session, $vc] = $this->callWithServer();
        $stopped = null;
        $this->afterAsked(function () use (&$stopped, $session): void {
            $this->setProcessEnv(['FAKE_CLAUDE_DELAY' => '0']);
            $stopped = $session->stop();
        });

        $started = hrtime(true);
        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(function () use (&$stopped): bool {
            return $stopped !== null;
        }, 'the call to be stopped');
        $this->waitUntil(fn () => ! $this->isRunning($this->waitingClaudes()[0]), 'Claude Code to end', 3.0);
        await($stopped);

        $this->assertLessThan(4.0, (hrtime(true) - $started) / 1e9, 'The call did not wait for the answer.');
        $this->assertSame('the call stopped', $this->logged('Dropped an early question')[0]['reason']);
        $this->assertSame([], $this->logged('Used the early question'));
        $this->assertSame([], $this->logged('Claude answered'));
        $this->assertSame([], array_filter($this->sent, fn (string $message) => str_starts_with($message, '> **Alice:**')), 'Nothing was answered.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testIsNotAnsweredWhenTheCallWasLeftMeanwhile(): void
    {
        [$session, $vc] = $this->callWithServer();
        // Someone said the leave phrase while Alice paused: nothing is answered after it, and her question is no use.
        $this->afterAsked(fn () => $this->setProperty($session, VoiceSession::class, 'leaving', true));

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->logged('Dropped an early question') !== [], 'the question to be dropped');

        $this->assertSame('it was not answered', $this->logged('Dropped an early question')[0]['reason']);
        $this->assertSame([], $this->logged('Used the early question'));
        $this->assertSame([], array_filter($this->sent, fn (string $message) => str_starts_with($message, '> **Alice:**')), 'Nothing was answered.');
        $this->assertFalse($this->isRunning($this->waitingClaudes()[0]));
        $this->setProperty($session, VoiceSession::class, 'leaving', false);
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
        // Who is in the call can't be read once, while the question is built.
        $this->voiceStates = $states = new class () extends \ArrayObject {
            public bool $broken = false;

            public function getIterator(): \Iterator
            {
                if ($this->broken) {
                    $this->broken = false;

                    throw new \RuntimeException('The voice states are not there.');
                }

                return parent::getIterator();
            }
        };
        $this->inCall('555');
        [$session, $vc] = $this->callWithServer();

        $states->broken = true;
        $this->says($vc, '555', self::QUESTION);
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
        $atEnd = null;
        $this->atEnd(function () use (&$atEnd): void {
            $atEnd = ['lookups' => $this->lookups(), 'asked' => count($this->claudeCalls())];
        });

        $this->says($vc, '555', self::QUESTION);
        $this->waitUntil(fn () => $this->lookups() !== [], 'the lookup to start');

        // The early answer is whole by the time the sentence is over, hand-off line and all: nothing was started by it.
        $this->assertSame(1, $atEnd['asked'], 'Claude was asked before the sentence was over.');
        $this->assertSame([], $atEnd['lookups']);
        $this->assertSame('> **Alice:** ' . self::QUESTION . "\nLet me look into that.", $this->sent[0]);
    }

    /**
     * Does something one tick after Claude is first asked early, which is well inside the 0.3 s before the sentence is over.
     */
    private function afterAsked(Closure $do): void
    {
        $this->hooks[] = ['Asked Claude', $do, true, false];
    }

    /**
     * Does something at the moment the call logs that the sentence ended.
     */
    private function atEnd(Closure $do): void
    {
        $this->hooks[] = ['Utterance ended', $do, false, false];
    }

    /**
     * @return array<int, mixed> The questions that were asked early and are neither used nor dropped.
     */
    private function openQuestions(VoiceSession $session): array
    {
        return (new \ReflectionProperty(VoiceSession::class, 'questions'))->getValue($session);
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
     * @return list<array{prompt: string, system: string}> The times Claude Code was run to look something up.
     */
    private function lookups(): array
    {
        return array_values(array_filter($this->claudeCalls(), fn (array $call) => str_starts_with($call['system'], 'You look things up')));
    }
}
