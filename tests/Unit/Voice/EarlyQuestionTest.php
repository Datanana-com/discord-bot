<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Voice\EarlyQuestion;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;
use function React\Promise\set_rejection_handler;

final class EarlyQuestionTest extends TestCase
{
    private int $ended = 0;

    public function testHoldsWhatClaudeWritesUntilItIsUsedAndHandsItOnInOrder(): void
    {
        $question = $this->asked();
        $timing = ['ms' => 400, 'init_ms' => null, 'retries' => 0, 'rate_limits' => 0];

        $question->started($timing);
        $question->text('It is a quarter');
        $question->text(' past four.');

        $events = [];
        $asked = $question->adopt(
            function (string $text) use (&$events): void {
                $events[] = "text:{$text}";
            },
            function (array $timing) use (&$events): void {
                $events[] = "started:{$timing['ms']}";
            },
        );

        $this->assertSame(['started:400', 'text:It is a quarter', 'text: past four.'], $events, 'What was held comes in the order it was written.');

        // What Claude writes after that goes straight on.
        $question->text(' Really.');
        $question->started($timing);

        $this->assertSame(['started:400', 'text:It is a quarter', 'text: past four.', 'text: Really.', 'started:400'], $events);
        $this->assertCount(3, $asked);
        $this->assertSame(0, $this->ended);
    }

    public function testIsOnlyUsedWhenWhatIsAskedNowIsWhatItWasAskedByteForByte(): void
    {
        $question = $this->asked();

        $this->assertTrue($question->matches('Prompt'));
        $this->assertFalse($question->matches('Prompt '));
        $this->assertFalse($question->matches('prompt'));
        $this->assertFalse($question->matches(''));
    }

    public function testDroppingEndsClaudeCodeOnceAndItIsNeverUsedAfterwards(): void
    {
        $question = $this->asked();
        $question->text('Held.');

        $this->assertTrue($question->drop());
        $this->assertSame(1, $this->ended);
        $this->assertFalse($question->matches('Prompt'), 'It is over.');
        $this->assertFalse($question->drop(), 'Nothing is there to drop.');
        $this->assertSame(1, $this->ended, 'Claude Code was ended once.');
    }

    public function testCanNotBeDroppedOnceItWasUsed(): void
    {
        $question = $this->asked();
        $question->adopt(static fn () => null, null);

        $this->assertFalse($question->matches('Prompt'), 'It was used.');
        $this->assertFalse($question->drop());
        $this->assertSame(0, $this->ended, 'The answer is the one someone is waiting for: it is not ended.');
    }

    public function testNoStartTimingIsNeededToUseIt(): void
    {
        $question = $this->asked();
        $question->started(['ms' => 1, 'init_ms' => null, 'retries' => 0, 'rate_limits' => 0]);
        $texts = [];
        $question->adopt(function (string $text) use (&$texts): void {
            $texts[] = $text;
        }, null);
        $question->text('Hi.');

        $this->assertSame(['Hi.'], $texts);
    }

    public function testSaysHowLongAgoItWasAsked(): void
    {
        $question = $this->asked();
        usleep(20_000);

        $this->assertGreaterThanOrEqual(15, $question->ageMs());
        $this->assertLessThan(2000, $question->ageMs());
    }

    public function testAnAnswerThatFailsWhileItIsHeldIsNotReportedAsUnhandled(): void
    {
        $unhandled = [];
        $previous = set_rejection_handler(function (Throwable $e) use (&$unhandled) {
            $unhandled[] = $e->getMessage();
        });

        try {
            // Its question was dropped, and Claude Code ended while it was asked: nobody waits for the answer.
            $question = new EarlyQuestion('555', 'Prompt', '[10:00:00] ');
            $question->asked([reject(new RuntimeException('Claude Code was ended')), resolve(null), static fn () => null]);
            unset($question);
            gc_collect_cycles();
        } finally {
            set_rejection_handler($previous);
        }

        $this->assertSame([], $unhandled);
    }

    private function asked(): EarlyQuestion
    {
        $question = new EarlyQuestion('555', 'Prompt', '[10:00:00] ');
        $question->asked([resolve('It is a quarter past four.'), resolve(null), function (): void {
            $this->ended++;
        }]);

        return $question;
    }
}
