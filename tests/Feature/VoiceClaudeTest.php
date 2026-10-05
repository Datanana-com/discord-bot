<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Voice\VoiceSession;

use function React\Async\await;

/**
 * How Claude Code is run for a call: a process is already running and waiting for each question,
 * so that the question doesn't wait for Claude Code to start.
 */
final class VoiceClaudeTest extends VoiceTestCase
{
    private const string QUESTION = 'Hey Claude, what time is it?';

    private const string PROMPT = "Transcript of the voice call so far:\n\nAlice: Hey Claude, what time is it?\n\nAlice is talking to you. Reply to their last message.";

    public function testOnlyTheAnswersOfACallAreWrittenWithoutThinking(): void
    {
        // Whoever runs the bot may have set how much Claude thinks.
        $this->setProcessEnv(['MAX_THINKING_TOKENS' => '4000']);
        $this->inCall('555');
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', self::QUESTION);
        await($session->stop());

        // The answer, the call's summary, and the update of Alice's memory.
        $calls = $this->claudeCalls();
        $this->assertCount(3, $calls);
        $this->assertStringContainsString('Discord voice call', $calls[0]['system']);
        $this->assertSame('0', $calls[0]['thinking'], 'Thinking takes seconds before the first word of an answer.');
        $this->assertSame(['4000', '4000'], [$calls[1]['thinking'], $calls[2]['thinking']], 'Nobody waits for these.');

        // None of them loads the settings of the user the bot runs as.
        foreach ($calls as $call) {
            $this->assertStringContainsString("arg=--setting-sources\narg=\n", $call['arguments']);
        }

        // Only a question is answered by a process that was waiting for it.
        $this->assertSame([true, false, false], array_column($calls, 'waited'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAQuestionIsAnsweredByTheProcessThatWasWaitingForIt(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // Claude Code is started with the call, and given nothing until someone asks.
        $this->waitUntil(fn () => count($this->waitingClaudes()) === 1, 'Claude Code to be started');
        [$waiting] = $this->waitingClaudes();
        $this->assertTrue($this->isRunning($waiting));
        $this->assertSame([], $this->claudeCalls());

        $this->ask($vc, '555', self::QUESTION);

        // It got the same prompt as a process started for the question would.
        $calls = $this->claudeCalls();
        $this->assertCount(1, $calls);
        $this->assertSame([true, $waiting, self::PROMPT], [$calls[0]['waited'], $calls[0]['pid'], $calls[0]['prompt']]);
        $this->assertSame(['> **Alice:** ' . self::QUESTION . "\nIt is a quarter past four."], $this->sent);

        // It has ended, having answered its one question, and another one waits for the next.
        $this->waitUntil(fn () => count($this->waitingClaudes()) === 2 && ! $this->isRunning($waiting), 'Claude Code to be replaced');
        $this->assertTrue($this->isRunning($this->waitingClaudes()[1]));
        $this->assertCount(1, $this->claudeCalls(), 'Which was given nothing yet.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testEveryQuestionGetsAProcessThatKnowsNothingOfTheOnesBefore(): void
    {
        $this->memory()->save('555', '- Is building a game called Bananas.');
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', 'Hey Claude, my dog is called Rex.');
        $this->ask($vc, '666', 'Claude, what time is it?');

        $calls = $this->claudeCalls();
        $this->assertCount(2, $calls);
        $this->assertSame([true, true], array_column($calls, 'waited'));
        $this->assertSame($this->waitingClaudes(), [...array_column($calls, 'pid'), $this->waitingClaudes()[2] ?? 0], 'Each by its own process, and a third one waits.');

        // The first process was told what the bot remembers about Alice. The second one only knows the call
        // from its own prompt: the transcript, which holds what Alice asked and what she was answered.
        $this->assertStringContainsString('Bananas', $calls[0]['prompt']);
        $this->assertSame(
            "Transcript of the voice call so far:\n\nAlice: Hey Claude, my dog is called Rex.\nClaude: It is a quarter past four.\nBob: Claude, what time is it?\n\n"
            . 'Bob is talking to you. Reply to their last message.',
            $calls[1]['prompt'],
        );
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAWaitingProcessThatEndsByItselfIsReplaced(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->waitUntil(fn () => count($this->waitingClaudes()) === 1, 'Claude Code to be started');

        // Claude Code ends by itself when it is left waiting for some minutes: here, after the two seconds
        // that tell it apart from one that can't start.
        $this->runFor(2.1);
        posix_kill($this->waitingClaudes()[0], SIGTERM);
        $this->waitUntil(fn () => count($this->waitingClaudes()) === 2, 'Claude Code to be replaced');

        // That is expected, so nothing is logged about it, and the question is still answered by a process that was waiting.
        $this->ask($vc, '555', self::QUESTION);

        $calls = $this->claudeCalls();
        $this->assertSame([[true, $this->waitingClaudes()[1], self::PROMPT]], [[$calls[0]['waited'], $calls[0]['pid'], $calls[0]['prompt']]]);
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAQuestionIsNotLostWhenNoProcessIsWaiting(): void
    {
        // A Claude Code that can't wait: it ends as soon as it has started.
        $this->setProcessEnv(['FAKE_CLAUDE_WAITING_LEAVES' => '1']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->waitUntil(fn () => count($this->waitingClaudes()) === 1, 'Claude Code to be started');

        // It isn't started over and over.
        $this->runFor(0.5);
        $this->assertCount(1, $this->waitingClaudes());
        $this->assertSame([], $this->loggedProblems(), 'Nothing is wrong until someone asks.');

        $this->ask($vc, '555', self::QUESTION);

        // The question is asked the way it was before: a process is started for it, which doesn't think either.
        $calls = $this->claudeCalls();
        $this->assertCount(1, $calls);
        $this->assertSame([false, self::PROMPT, '0'], [$calls[0]['waited'], $calls[0]['prompt'], $calls[0]['thinking']]);
        $this->assertSame(['> **Alice:** ' . self::QUESTION . "\nIt is a quarter past four."], $this->sent);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');
        $this->assertSame(['No Claude Code process was waiting for the question'], $this->loggedProblems());
        $this->assertSame('555', $this->logged('No Claude Code process was waiting for the question')[0]['user']);

        // One is started again for the next question, once.
        $this->waitUntil(fn () => count($this->waitingClaudes()) === 2, 'Claude Code to be started again');
        $this->runFor(0.5);
        $this->assertCount(2, $this->waitingClaudes());
        $this->assertCount(1, $this->played, 'And the question was answered once.');
    }

    public function testAQuestionIsNotLostWhenTheWaitingProcessEndsWithoutWritingAnything(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_WAITING_FAILS' => '1']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->ask($vc, '555', self::QUESTION);

        // The process that was waiting was given the question and ended. Another one was started for it.
        $calls = $this->claudeCalls();
        $this->assertCount(1, $calls);
        $this->assertSame([false, self::PROMPT, '0'], [$calls[0]['waited'], $calls[0]['prompt'], $calls[0]['thinking']]);
        $this->assertSame(['> **Alice:** ' . self::QUESTION . "\nIt is a quarter past four."], $this->sent);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');
        $this->runFor(0.3);
        $this->assertCount(1, $this->played, 'Nothing was spoken twice.');

        // Which is logged, without the question.
        $problems = $this->loggedProblems();
        $this->assertCount(1, $problems);
        $this->assertStringStartsWith('The waiting Claude Code process did not answer: ', $problems[0]);
        $this->assertStringEndsWith('fake-claude exited with code 1', $problems[0]);
        $this->assertSame('555', $this->logged($problems[0])[0]['user']);
        $this->assertLogsNeverMention('what time is it');

        // The next question is answered by the process that waits for it again.
        $this->setProcessEnv(['FAKE_CLAUDE_WAITING_FAILS' => '']);
        $this->ask($vc, '666', 'Claude, are you still there?');

        $this->assertSame([false, true], array_column($this->claudeCalls(), 'waited'));
        $this->assertCount(1, $this->loggedProblems());
    }

    public function testAQuestionIsNotAskedAgainOnceClaudeStartedToAnswerIt(): void
    {
        // Claude Code crashes while Claude is writing its answer.
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => self::claudeText('It is a quarter past four. '), 'FAKE_CLAUDE_EXIT' => '3']);
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->loggedProblems() !== [], 'the failure to be logged');

        // Its first sentence was spoken: asking another process would say it again.
        $this->assertCount(1, $this->claudeCalls());
        $this->assertCount(1, $this->played);
        $this->assertCount(1, $this->sent);
        $this->assertStringStartsWith("Sorry, I couldn't get an answer from Claude. (", $this->sent[0]);
        $this->assertCount(1, $this->loggedProblems());
        $this->assertStringStartsWith('Voice reply failed: ', $this->loggedProblems()[0]);
        $this->assertStringEndsWith('fake-claude exited with code 3', $this->loggedProblems()[0]);

        // The next question still has a process waiting for it.
        $this->waitUntil(fn () => count($this->waitingClaudes()) === 2, 'Claude Code to be replaced');
    }

    public function testTheWaitingProcessIsEndedWhenTheCallEnds(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);
        $this->waitUntil(fn () => count($this->waitingClaudes()) === 1, 'Claude Code to be started');
        [$waiting] = $this->waitingClaudes();

        await($session->stop());

        $this->waitUntil(fn () => ! $this->isRunning($waiting), 'Claude Code to end');
        $this->assertCount(1, $this->waitingClaudes(), 'No other one is started: no question is coming.');
        $this->assertSame([], $this->claudeCalls(), 'It was never asked anything.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testTheWaitingProcessIsEndedWhenTheBotIsDisconnected(): void
    {
        VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);
        $this->waitUntil(fn () => count($this->waitingClaudes()) === 1, 'Claude Code to be started');
        [$waiting] = $this->waitingClaudes();

        // Someone disconnects the bot from the voice channel.
        $vc->emit('close');

        $this->waitUntil(fn () => ! $this->isRunning($waiting), 'Claude Code to end');
        $this->assertCount(1, $this->waitingClaudes());
        $this->assertSame([], $this->loggedProblems());
    }

    public function testAProcessThatIsAnsweringWhenTheCallEndsFinishesItsAnswer(): void
    {
        $this->setProcessEnv([
            'FAKE_CLAUDE_OUTPUT' => self::claudeStream('It is a quarter past four. ', 'Time for a cup of tea.'),
            'FAKE_CLAUDE_PAUSE' => '10',
        ]);
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel(), connected: true), $channel, $this->discord);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the first sentence to be spoken');
        [$answering] = $this->waitingClaudes();

        $ended = $session->stop();
        $this->runFor(0.3);
        $this->assertTrue($this->isRunning($answering), 'Only a process that waits is ended with the call.');

        touch($this->claudeResume);
        await($ended);

        // The answer is posted whole, then the summary, which a process started for it writes.
        $this->assertSame('> **Alice:** ' . self::QUESTION . "\nIt is a quarter past four. Time for a cup of tea.", $this->sent[0]);
        $this->assertSame([[true, $answering], [false, $this->claudeCalls()[1]['pid']]], array_map(fn (array $call) => [$call['waited'], $call['pid']], $this->claudeCalls()));
        $this->assertFalse($this->isRunning($answering));
        $this->assertCount(1, $this->waitingClaudes(), 'No process waits for a question after the call.');
        $this->assertSame([], $this->loggedProblems());
    }
}
