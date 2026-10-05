<?php

declare(strict_types=1);

namespace App\Voice;

use Closure;

/**
 * Splits a text that arrives piece by piece into sentences, so each one can be spoken
 * as soon as it is complete, while the rest of the text is still being written.
 */
final class SentenceSplitter
{
    /** Shorter sentences ("Sure.") are joined to the next one, or the speech sounds choppy. */
    private const int MIN_CHARACTERS = 20;

    /**
     * A sentence ends after its punctuation once a space follows, which rules out "3.50" and
     * waits for "?!" to be complete; right after punctuation that takes no space; or with its line.
     */
    private const string SENTENCE = '/^.{' . self::MIN_CHARACTERS . ',}?(?:(?<=[.!?…])(?=\s)|(?<=[。！？])|(?=\n))/su';

    /** What arrived of the sentence in progress. */
    private string $text = '';

    /**
     * @param Closure(string $sentence): void $onSentence Called with each complete sentence.
     */
    public function __construct(private readonly Closure $onSentence)
    {
    }

    /**
     * Adds the next piece of the text.
     */
    public function push(string $text): void
    {
        $this->text = ltrim($this->text . $text);

        while (preg_match(self::SENTENCE, $this->text, $match) === 1) {
            $this->text = ltrim(substr($this->text, strlen($match[0])));
            ($this->onSentence)($match[0]);
        }
    }

    /**
     * Hands over what is left, when the text is complete.
     */
    public function flush(): void
    {
        $rest = rtrim($this->text);
        $this->text = '';

        if ($rest !== '') {
            ($this->onSentence)($rest);
        }
    }
}
