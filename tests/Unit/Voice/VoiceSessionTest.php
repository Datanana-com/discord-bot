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
        yield 'no wake word answers what has no words too' => ['...?', '', true];
        yield 'multi-word wake word' => ['Okay computer, play some music', 'okay computer', true];
        yield 'a dot in the wake word is a dot' => ['Is abi here?', 'a.i', false];

        // Whisper punctuates what it hears, so what it puts between the words of a wake word doesn't count.
        yield 'a comma between the words' => ['Okay, computer, play some music', 'okay computer', true];
        yield 'an ellipsis between the words' => ['Hey... Jarvis! What time is it?', 'hey jarvis', true];
        yield 'a dash between the words' => ['Okay - computer, play some music', 'okay computer', true];
        yield 'three words' => ['Hey, there. Jarvis?', 'hey there jarvis', true];
        yield 'more than one space in the wake word' => ['Okay computer, play some music', 'okay  computer', true];
        yield 'a wide space in the wake word, as typed on a Japanese keyboard' => ['ヘイ、クロード、今何時？', "ヘイ\u{3000}クロード", true];
        yield 'another word between the words' => ['Okay, my computer is slow', 'okay computer', false];
        yield 'a number between the words' => ['Okay 2 computer', 'okay computer', false];
        yield 'the words the other way around' => ['Computer, okay?', 'okay computer', false];
        yield 'the words run together' => ['Okaycomputer, play some music', 'okay computer', false];
        yield 'only the first word' => ['Okay, play some music', 'okay computer', false];
        yield 'only part of the first word' => ['Tokay, computer', 'okay computer', false];
        yield 'only part of the last word' => ['Okay, computers are slow', 'okay computer', false];
        yield 'an accent that belongs to the first word' => ["Jose\u{301} Maria, what time is it?", 'jose maria', false];
        yield 'a word that ends with a vowel sign' => ['राजा, समय क्या है?', 'राजा', true];
        yield 'only part of a word that goes on with a vowel sign' => ['राजा आ गया', 'राज', false];
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
        yield 'after a line exactly as long as the limit' => ["aaaa bbbbb\ncc", 10, ['aaaa bbbbb', 'cc']];
        yield 'after a sentence exactly as long as the limit' => ['a a a a b. c', 10, ['a a a a b.', 'c']];
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
