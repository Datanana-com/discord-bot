<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Voice\VoiceSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VoiceSessionTest extends TestCase
{
    #[DataProvider('texts')]
    public function testMentions(string $text, string $wakeWord, bool $expected): void
    {
        $this->assertSame($expected, VoiceSession::mentions($text, $wakeWord));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function texts(): iterable
    {
        yield 'at the start' => ['Claude, what time is it?', 'claude', true];
        yield 'at the end' => ['What do you think, Claude?', 'claude', true];
        yield 'any case' => ['hey CLAUDE are you there', 'claude', true];
        yield 'not mentioned' => ['What time is it?', 'claude', false];
        yield 'only part of a word' => ['Claudette is here', 'claude', false];
        yield 'no wake word answers everything' => ['What time is it?', '', true];
        yield 'multi-word wake word' => ['Okay computer, play some music', 'okay computer', true];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('longTexts')]
    public function testSplit(string $text, int $limit, array $expected): void
    {
        $this->assertSame($expected, VoiceSession::split($text, $limit));
    }

    /**
     * @return iterable<string, array{string, int, list<string>}>
     */
    public static function longTexts(): iterable
    {
        yield 'short enough to stay whole' => ['One. Two.', 20, ['One. Two.']];
        yield 'exactly as long as the limit' => [str_repeat('a', 20), 20, [str_repeat('a', 20)]];
        yield 'after a line' => ["- First item\n- Second item\n- Third", 20, ['- First item', '- Second item', '- Third']];
        yield 'after as many lines as fit' => ["- First item\n- Second item\n- Third", 30, ["- First item\n- Second item", '- Third']];
        yield 'after a sentence, when a line is too long' => ['One two three. Four five six! Seven?', 20, ['One two three.', 'Four five six!', 'Seven?']];
        yield 'after a sentence without spaces' => ['それは良い考えです。明日また話しましょう。', 20, ['それは良い考えです。', '明日また話しましょう。']];
        yield 'after a word, when a sentence is too long' => ['one two three four five six seven', 20, ['one two three four', 'five six seven']];
        yield 'anywhere, when a word is too long' => [str_repeat('a', 45), 20, [str_repeat('a', 20), str_repeat('a', 20), 'aaaaa']];
        yield 'counting characters, not bytes' => ['ééééé ééééé ééééé ééééé', 20, ['ééééé ééééé ééééé', 'ééééé']];
    }

    public function testSplitFitsDiscordMessagesByDefault(): void
    {
        $sentence = 'They agreed to meet again on Friday. ';

        $parts = VoiceSession::split(trim(str_repeat($sentence, 100)));

        // 54 sentences of 37 characters fit in 2000, without the space after the last one.
        $this->assertSame([1997, 1701], array_map(mb_strlen(...), $parts));
        $this->assertSame(trim(str_repeat($sentence, 54)), $parts[0]);
    }
}
