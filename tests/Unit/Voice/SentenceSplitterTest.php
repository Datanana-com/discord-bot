<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Voice\SentenceSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SentenceSplitterTest extends TestCase
{
    /** @var list<string> */
    private array $sentences = [];

    private SentenceSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new SentenceSplitter(function (string $sentence) {
            $this->sentences[] = $sentence;
        });
    }

    public function testHandsOverASentenceAsSoonAsTheNextOneStarts(): void
    {
        // Claude's text arrives a few words at a time.
        $this->splitter->push('It is a quarter');
        $this->splitter->push(' past four.');
        $this->assertSame([], $this->sentences, 'The sentence could still go on, as in "past four.30".');

        $this->splitter->push(' Time');
        $this->assertSame(['It is a quarter past four.'], $this->sentences);

        $this->splitter->push(' for a cup of tea.');
        $this->assertSame(['It is a quarter past four.'], $this->sentences, 'The second sentence waits for what follows it.');

        $this->splitter->flush();
        $this->assertSame(['It is a quarter past four.', 'Time for a cup of tea.'], $this->sentences);
    }

    public function testHandsOverTheFirstWordsOfTheTextBeforeTheirSentenceIsWhole(): void
    {
        $splitter = $this->firstWords(5);

        $splitter->push('The sky looks blue because');
        $this->assertSame([], $this->sentences, 'The fifth word could still go on.');

        // The sixth word has begun: the first five are whole, and the voice gets them while Claude writes on.
        $splitter->push(' o');
        $this->assertSame(['The sky looks blue because'], $this->sentences);

        // The rest of the sentence is the next piece, and after it only whole sentences are handed over.
        $splitter->push('f how sunlight scatters off the air. It');
        $this->assertSame(['The sky looks blue because', 'of how sunlight scatters off the air.'], $this->sentences);

        $splitter->push(' happens every single day above all of us');
        $this->assertSame(['The sky looks blue because', 'of how sunlight scatters off the air.'], $this->sentences);

        $splitter->flush();
        $this->assertSame(['The sky looks blue because', 'of how sunlight scatters off the air.', 'It happens every single day above all of us'], $this->sentences);
    }

    public function testHandsOverNoFirstWordsOfATextWhoseFirstSentenceIsAlreadyWhole(): void
    {
        $splitter = $this->firstWords(3);

        // Claude wrote faster than it was read: there is nothing to gain by cutting a sentence that is all there.
        $splitter->push('It is rather late now, my friend. And so we should');
        $this->assertSame(['It is rather late now, my friend.'], $this->sentences);

        $splitter->push(' all go home and sleep');
        $this->assertSame(['It is rather late now, my friend.'], $this->sentences, 'Only the start of the text is ever cut.');
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('firstWordsOfTexts')]
    public function testFirstWords(int $words, string $text, array $expected): void
    {
        $splitter = $this->firstWords($words);
        $splitter->push($text);
        $handedOver = $this->sentences;
        $splitter->flush();

        $this->assertSame($expected, $handedOver, 'Before the text is complete.');
        $this->assertSame($text, implode(' ', $this->sentences), 'Nothing of the text is lost or said twice.');
    }

    /**
     * @return iterable<string, array{int, string, list<string>}>
     */
    public static function firstWordsOfTexts(): iterable
    {
        yield 'as many words as asked' => [5, 'A refrigerator moves heat from inside the box', ['A refrigerator moves heat from']];
        yield 'not while the last of them could still go on' => [5, 'A refrigerator moves heat from', []];
        yield 'not fewer than there are' => [5, 'A refrigerator moves heat', []];
        yield 'more of them when they are too short to be spoken alone' => [3, 'It is a quarter past four in the', ['It is a quarter past']];
        yield 'exactly as long as the shortest sentence' => [1, 'This is twenty longs and goes on', ['This is twenty longs']];
        yield 'one character shorter than that goes on to the next word' => [1, 'It is nineteen long and goes on', ['It is nineteen long and']];
        yield 'not after a number' => [5, 'You should rest for 60 seconds between two sets', ['You should rest for 60 seconds']];
        yield 'not before a number' => [4, 'You should rest about 60 to 90 seconds between two sets', ['You should rest about 60 to 90 seconds']];
        yield 'not inside a name' => [4, 'I would ask Dr. Jane Miller about that today', ['I would ask Dr. Jane Miller']];
        yield 'the first word is capitalized as any first word is' => [1, 'Internationalization Matters a great deal', ['Internationalization']];
        yield 'a capitalized word before a plain one ends the piece' => [4, 'The capital of Portugal is the city', ['The capital of Portugal']];
        yield 'a plain word before a capitalized one ends the piece' => [4, 'We would really like Paris in the spring', ['We would really like']];
        yield 'not into the next line' => [3, "Short line here\nand then some more words follow it", []];
        yield 'counted in characters, not bytes' => [1, 'Ça été préparé à l’été déjà', ['Ça été préparé à l’été']];
        yield 'none in a text without spaces' => [2, 'それは本当に良い考えだと私は心から思いますそして', []];
    }

    public function testWaitsForWhatClosesWithASentenceThatTakesNoSpace(): void
    {
        $this->splitter->push('それは本当に「良い考えだと私は心から思います。');
        $this->assertSame([], $this->sentences, 'A bracket may close it.');

        $this->splitter->push('」明日');
        $this->assertSame(['それは本当に「良い考えだと私は心から思います。」'], $this->sentences);
    }

    private function firstWords(int $words): SentenceSplitter
    {
        return new SentenceSplitter(function (string $sentence) {
            $this->sentences[] = $sentence;
        }, $words);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('texts')]
    public function testSplit(string $text, array $expected): void
    {
        $this->splitter->push($text);
        $this->splitter->flush();

        $this->assertSame($expected, $this->sentences);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function texts(): iterable
    {
        yield 'after each kind of punctuation' => [
            'Is that the right time? It certainly is! We should get going… The others are waiting.',
            ['Is that the right time?', 'It certainly is! We should get going…', 'The others are waiting.'],
        ];
        yield 'a very short sentence is joined to the next one' => [
            'Sure. It is a quarter past four. See you there.',
            ['Sure. It is a quarter past four.', 'See you there.'],
        ];
        yield 'several very short sentences are joined' => [
            'Yes. No. Maybe. I really cannot tell you that.',
            ['Yes. No. Maybe. I really cannot tell you that.'],
        ];
        yield 'a sentence of exactly the shortest length stays alone' => [
            'This is twenty long. And here comes the next one.',
            ['This is twenty long.', 'And here comes the next one.'],
        ];
        yield 'a sentence one character too short is joined' => [
            'It is only 19 long. And here comes the next one.',
            ['It is only 19 long. And here comes the next one.'],
        ];
        yield 'a very short last sentence has nothing to be joined to' => [
            'It is a quarter past four. Bye!',
            ['It is a quarter past four.', 'Bye!'],
        ];
        yield 'not inside a number' => [
            'That costs about 3.50 dollars in total. Not much.',
            ['That costs about 3.50 dollars in total.', 'Not much.'],
        ];
        yield 'not after an abbreviated title' => [
            'O encontro com o Sr. Silva é amanhã cedo. Até lá.',
            ['O encontro com o Sr. Silva é amanhã cedo.', 'Até lá.'],
        ];
        yield 'not after a numbered day' => [
            'Das Treffen ist am 3. Oktober um zehn Uhr. Bis dann!',
            ['Das Treffen ist am 3. Oktober um zehn Uhr.', 'Bis dann!'],
        ];
        yield 'not after an initial' => [
            'The plan came from J. Smith and the whole team. Thanks.',
            ['The plan came from J. Smith and the whole team.', 'Thanks.'],
        ];
        yield 'not after e.g. or i.e.' => [
            'Bring something, e.g. snacks or drinks, i.e. anything. See you then.',
            ['Bring something, e.g. snacks or drinks, i.e. anything.', 'See you then.'],
        ];
        yield 'not after etc.' => [
            'Bring snacks, drinks, cups, etc. and some music too. See you.',
            ['Bring snacks, drinks, cups, etc. and some music too.', 'See you.'],
        ];
        yield 'after a year' => [
            'We shipped the first version in 2024. Then came the second one.',
            ['We shipped the first version in 2024.', 'Then came the second one.'],
        ];
        yield 'with the quote it closes' => [
            'He told me: "I will be there at four." Then he left the room.',
            ['He told me: "I will be there at four."', 'Then he left the room.'],
        ];
        yield 'with the German quote it closes' => [
            'Er sagte: „Komm bitte sofort zu mir her.“ Dann ging er.',
            ['Er sagte: „Komm bitte sofort zu mir her.“', 'Dann ging er.'],
        ];
        yield 'with the quote it closes, without spaces between sentences' => [
            '他在会议结束以后对大家说：“我们现在就走吧。”然后他就离开了。',
            ['他在会议结束以后对大家说：“我们现在就走吧。”', '然后他就离开了。'],
        ];
        yield 'with the bracket it closes, without spaces between sentences' => [
            'それは本当に「良い考えだと私は心から思います。」明日また詳しく話しましょう。',
            ['それは本当に「良い考えだと私は心から思います。」', '明日また詳しく話しましょう。'],
        ];
        yield 'after a danda' => [
            'यह सचमुच बहुत अच्छा विचार है। कल फिर बात करते हैं।',
            ['यह सचमुच बहुत अच्छा विचार है।', 'कल फिर बात करते हैं।'],
        ];
        yield 'punctuation that repeats' => [
            'Wait... are you sure about that?! I am not so sure.',
            ['Wait... are you sure about that?!', 'I am not so sure.'],
        ];
        yield 'at the end of a line' => [
            "First, preheat the oven\nThen mix the flour and the eggs\n\nBake it for an hour.",
            ['First, preheat the oven', 'Then mix the flour and the eggs', 'Bake it for an hour.'],
        ];
        yield 'sentences without spaces between them' => [
            'それは本当に良い考えだと私は心から思います。明日また詳しく話しましょう。',
            ['それは本当に良い考えだと私は心から思います。', '明日また詳しく話しましょう。'],
        ];
        yield 'counting characters, not bytes' => [
            'Ça a été créé là. Voilà où nous en sommes maintenant.',
            ['Ça a été créé là. Voilà où nous en sommes maintenant.'],
        ];
        yield 'spaces around the text are dropped' => [
            "  \n It is a quarter past four.  \n",
            ['It is a quarter past four.'],
        ];
        yield 'nothing' => ['', []];
        yield 'only spaces' => [" \n ", []];
    }

    public function testStartsOverAfterAFlush(): void
    {
        $this->splitter->push('The first answer ends here');
        $this->splitter->flush();
        $this->splitter->flush();
        $this->splitter->push('and this is another one.');
        $this->splitter->flush();

        $this->assertSame(['The first answer ends here', 'and this is another one.'], $this->sentences);
    }
}
