<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\HandOff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HandOffTest extends TestCase
{
    /** @var list<string> The pieces of the answer that were passed on, to be spoken. */
    private array $passed = [];

    /**
     * @return array<string, array{string, string, string|null}> An answer, what it says, and what it hands off.
     */
    public static function answers(): array
    {
        return [
            'no line' => ['It is a quarter past four.', 'It is a quarter past four.', null],
            'the line at the end' => ["Let me look into that.\nLOOK UP: Find the latest stable version of PHP.", 'Let me look into that.', 'Find the latest stable version of PHP.'],
            'blank lines around the line' => ["Let me look into that.\n\nLOOK UP:   Compare the prices.  \n\n", 'Let me look into that.', 'Compare the prices.'],
            'Windows line endings' => ["Let me look into that.\r\nLOOK UP: Compare the prices.\r\n", 'Let me look into that.', 'Compare the prices.'],
            'nothing but the line' => ['LOOK UP: Compare the prices.', '', 'Compare the prices.'],
            'several lines before the line' => ["Good question.\nLet me look into that.\nLOOK UP: Compare the prices.", "Good question.\nLet me look into that.", 'Compare the prices.'],
            // Only the end of the answer hands something off: anywhere else, it is something Claude says.
            'the line in the middle' => ["LOOK UP: Compare the prices.\nActually, they cost the same.", "LOOK UP: Compare the prices.\nActually, they cost the same.", null],
            'the line twice' => ["LOOK UP: Compare the prices.\nLOOK UP: Find the cheapest.", 'LOOK UP: Compare the prices.', 'Find the cheapest.'],
            'in the middle of a line' => ['You could LOOK UP: the prices yourself.', 'You could LOOK UP: the prices yourself.', null],
            'at the end of the last line' => ["Sure.\nI will LOOK UP: the prices", "Sure.\nI will LOOK UP: the prices", null],
            'indented' => ["Sure.\n  LOOK UP: the prices", "Sure.\n  LOOK UP: the prices", null],
            'in lower case' => ["Sure.\nlook up: the prices", "Sure.\nlook up: the prices", null],
            'without the colon' => ["Sure.\nLOOK UP the prices", "Sure.\nLOOK UP the prices", null],
            'a line that only starts like it' => ["Sure.\nLOOK", "Sure.\nLOOK", null],
            // Nothing to look up, and still not something to say.
            'the line without a task' => ["Let me look into that.\nLOOK UP:  ", 'Let me look into that.', null],
            'an empty answer' => ['', '', null],
        ];
    }

    #[DataProvider('answers')]
    public function testSplitsAnAnswerIntoWhatItSaysAndWhatItHandsOff(string $answer, string $said, ?string $task): void
    {
        $this->assertSame([$said, $task], HandOff::split($answer));
    }

    #[DataProvider('answers')]
    public function testFindsTheSameHoweverTheAnswerArrives(string $answer, string $said, ?string $task): void
    {
        // Claude writes its answer in pieces of any size: cut everywhere in two, and letter by letter.
        $cuts = [str_split($answer === '' ? ' ' : $answer)];

        for ($cut = 0; $cut <= strlen($answer); $cut++) {
            $cuts[] = [substr($answer, 0, $cut), substr($answer, $cut)];
        }

        foreach ($cuts as $pieces) {
            $this->passed = [];
            $handOff = new HandOff($this->collect(...));
            array_map($handOff->push(...), $pieces);

            $this->assertSame($task, $handOff->flush(), json_encode($pieces));
            $this->assertSame($said, trim(implode('', $this->passed)), json_encode($pieces));
        }
    }

    public function testNeverPassesOnAnyOfTheLineThatHandsOff(): void
    {
        $answer = "Let me look into that.\nLOOK UP: Find the latest stable version of PHP.";

        for ($cut = 0; $cut <= strlen($answer); $cut++) {
            $this->passed = [];
            $handOff = new HandOff($this->collect(...));
            $handOff->push(substr($answer, 0, $cut));

            // Whatever arrived so far, what was passed on is the start of the sentence before the line, and no more.
            $this->assertTrue(str_starts_with("Let me look into that.\n", implode('', $this->passed)), "After {$cut} characters.");

            $handOff->push(substr($answer, $cut));
            $this->assertSame("Let me look into that.\n", implode('', $this->passed), "After {$cut} characters.");
        }
    }

    public function testPassesOnEachPieceAsSoonAsItArrives(): void
    {
        $handOff = new HandOff($this->collect(...));

        // An answer is spoken while it is written, so nothing waits for the end of its line.
        $handOff->push('It is a quarter');
        $this->assertSame(['It is a quarter'], $this->passed);

        $handOff->push(" past four.\n");
        $handOff->push('Time for tea. LOOK UP: is not at the start of this line.');
        $this->assertSame(['It is a quarter', " past four.\n", 'Time for tea. LOOK UP: is not at the start of this line.'], $this->passed);
        $this->assertNull($handOff->flush());
    }

    public function testHoldsTheStartOfALineUntilItIsKnownNotToHandOff(): void
    {
        $handOff = new HandOff($this->collect(...));

        $handOff->push("Sure.\nLOO");
        $this->assertSame(["Sure.\n"], $this->passed, 'It may still become the line.');

        $handOff->push('K at this');
        $this->assertSame(["Sure.\n", 'LOOK at this'], $this->passed, 'It did not.');

        $handOff->push(" instead.\nL");
        $this->assertSame(["Sure.\n", 'LOOK at this', " instead.\n"], $this->passed);

        // The answer ends there: what was held is the end of what it says.
        $this->assertNull($handOff->flush());
        $this->assertSame(["Sure.\n", 'LOOK at this', " instead.\n", 'L'], $this->passed);
    }

    public function testPassesOnALineThatLookedLikeTheHandOffOnceAnotherLineFollows(): void
    {
        $handOff = new HandOff($this->collect(...));

        $handOff->push("LOOK UP: Compare the prices.\n\n");
        $this->assertSame([], $this->passed, 'Blank lines after it change nothing.');

        $handOff->push('Actually');
        $this->assertSame(["LOOK UP: Compare the prices.\n\nActually"], $this->passed);

        $handOff->push(', they cost the same.');
        $this->assertNull($handOff->flush());
        $this->assertSame("LOOK UP: Compare the prices.\n\nActually, they cost the same.", implode('', $this->passed));
    }

    public function testHandsOffOnlyOnce(): void
    {
        $handOff = new HandOff($this->collect(...));
        $handOff->push("Let me look into that.\nLOOK UP: Compare the prices.");

        $this->assertSame('Compare the prices.', $handOff->flush());
        $this->assertNull($handOff->flush());
        $this->assertSame(["Let me look into that.\n"], $this->passed);
    }

    private function collect(string $text): void
    {
        $this->passed[] = $text;
    }
}
