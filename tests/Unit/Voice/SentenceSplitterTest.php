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

    public function testWaitsForWhatClosesWithASentenceThatTakesNoSpace(): void
    {
        $this->splitter->push('それは本当に「良い考えだと私は心から思います。');
        $this->assertSame([], $this->sentences, 'A bracket may close it.');

        $this->splitter->push('」明日');
        $this->assertSame(['それは本当に「良い考えだと私は心から思います。」'], $this->sentences);
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
