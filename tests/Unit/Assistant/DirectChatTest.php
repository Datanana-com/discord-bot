<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\DirectChat;
use PHPUnit\Framework\TestCase;

final class DirectChatTest extends TestCase
{
    public function testKeepsAnAnswerThatFitsInOneMessageWhole(): void
    {
        // Close to Discord's limit, where a code block cut in two would need room for its fences.
        $answer = trim(str_repeat('They agreed to meet again on Friday. ', 54));

        $this->assertSame(1997, mb_strlen($answer));
        $this->assertSame([$answer], DirectChat::parts($answer));
    }

    public function testLeavesTheEndOfAnAnswerAsClaudeWroteIt(): void
    {
        // A code block Claude didn't close is only closed where the answer is cut, so it still fits.
        $answer = "```\n" . trim(str_repeat("echo 'They agreed to meet again on Friday.'\n", 45)) . "\necho 'Done!!!'";

        $this->assertSame(1998, mb_strlen($answer));
        $this->assertSame([$answer], DirectChat::parts($answer));
    }

    public function testDoesNotTakeInlineCodeAtTheStartOfALineForACodeBlock(): void
    {
        $lines = array_map(fn (int $line) => sprintf('%02d. Then check that %s works.', $line, str_repeat('it ', 10)), range(1, 60));
        $answer = "```npm test``` runs the tests.\n" . implode("\n", $lines);

        $parts = DirectChat::parts($answer);

        // Nothing was opened, so nothing is closed or opened again.
        $this->assertCount(2, $parts);
        $this->assertSame($answer, implode("\n", $parts));
    }

    public function testKeepsTheIndentationOfACodeBlockCutInTwo(): void
    {
        // Every line after the first is indented, so the second message starts with an indented one.
        $methods = array_map(fn (int $line) => sprintf("    def f%02d(self, x):\n        return x + %02d", $line, $line), range(1, 60));
        $answer = "```python\nclass Numbers:\n" . implode("\n", $methods) . "\n```";

        $parts = DirectChat::parts($answer);

        $this->assertCount(2, $parts);
        $this->assertStringEndsWith("\n```", $parts[0]);
        $this->assertMatchesRegularExpression('/^```python\n {4}/', $parts[1]);
        $this->assertSame($answer, preg_replace("/\n```\n```python\n/", "\n", implode("\n", $parts)));
    }
}
