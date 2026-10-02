<?php

declare(strict_types=1);

namespace Tests\Voice;

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
}
