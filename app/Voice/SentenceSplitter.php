<?php

declare(strict_types=1);

namespace App\Voice;

use Closure;

/**
 * Splits a text that arrives piece by piece into sentences, so each one can be spoken
 * as soon as it is complete, while the rest of the text is still being written.
 *
 * It can also hand over the first words of the text before their sentence is whole, so that the first sound of
 * an answer doesn't wait for a long first sentence: the rest of that sentence then follows as a piece of its own.
 */
final class SentenceSplitter
{
    /** Shorter sentences ("Sure.") are joined to the next one, or the speech sounds choppy. */
    private const int MIN_CHARACTERS = 20;

    /**
     * Where a sentence ends, for a pattern that has just matched its punctuation: after the quotes
     * or brackets that close with it, once a space follows, which rules out "3.50" and waits for
     * "?!" to be complete; or, after punctuation that takes no space, once something else follows.
     *
     * A dot doesn't end it after what is usually abbreviated or numbered: a capitalized word of one
     * or two letters ("Sr.", "Dr.", an initial), a number of one or two digits ("am 3. Oktober"),
     * "etc.", "e.g." or "i.e.". Not after three letters, which German nouns ("Uhr.") often end a
     * sentence with. Each look back has its own fixed length, as older PCRE versions require.
     */
    public const string END = '(?:'
        . '(?<=[.!?…।])(?<!\b\p{Lu}\.)(?<!\b\p{Lu}\p{Ll}\.)(?<!\b\d\.)(?<!\b\d\d\.)'
        . '(?<!\betc\.)(?<!\be\.g\.)(?<!\bi\.e\.)["\'“”‘’«»)\]]*(?=\s)'
        . '|(?<=[。！？])[」』）”’]*(?=[^」』）”’])'
        . ')';

    /** A sentence ends where {@see END} says, or with its line. */
    private const string SENTENCE = '/^.{' . self::MIN_CHARACTERS . ',}?(?:' . self::END . '|(?=\n))/su';

    /** The rest of a sentence whose first words were handed over ends there too, however short it is. */
    private const string REST = '/^.+?(?:' . self::END . '|(?=\n))/su';

    /** A word and the space after it, once the next word of the same line has begun: only then is it known to be whole. */
    private const string WORD = '/\G(\S+)[^\S\n]+(?=\S)/u';

    /** What arrived of the sentence in progress. */
    private string $text = '';

    /** Whether anything was handed over yet: only the start of the text is handed over before its sentence is whole. */
    private bool $started = false;

    /** Whether the first words were handed over, and the rest of their sentence was not yet. */
    private bool $cut = false;

    /**
     * @param Closure(string $sentence): void $onSentence Called with each complete sentence.
     * @param int $firstWords How many words of the text are handed over as soon as they are there, before the
     *                        sentence they start is whole. 0 for none: whole sentences only.
     */
    public function __construct(private readonly Closure $onSentence, private readonly int $firstWords = 0)
    {
    }

    /**
     * Adds the next piece of the text.
     */
    public function push(string $text): void
    {
        $this->text = ltrim($this->text . $text);

        // The rest of a sentence that was cut is not joined to the next one when it is short: the voice has
        // spoken its start, and whoever listens waits for its end.
        while (preg_match($this->cut ? self::REST : self::SENTENCE, $this->text, $match) === 1) {
            $this->started = true;
            $this->cut = false;
            $this->text = ltrim(substr($this->text, strlen($match[0])));
            ($this->onSentence)($match[0]);
        }

        if (! $this->started && $this->firstWords > 0 && ($words = $this->firstWords()) !== null) {
            $this->started = true;
            $this->cut = true;
            $this->text = substr($this->text, strlen($words));
            ($this->onSentence)(rtrim($words));
        }
    }

    /**
     * The first words of the text, with the space after them, once there are enough of them and the text can be
     * cut after them: null until then.
     *
     * It is not cut before {@see MIN_CHARACTERS}, like a sentence, nor next to a number ("60 to 90 seconds",
     * "3 000") or between two capitalized words, as in most names ("New York", "Dr. Jane Miller"): a voice
     * reads each piece on its own, and would read the two halves as two things. It then goes on to the next
     * word after which it can be. A name with a small word in it ("Ludwig van Beethoven") can still be cut.
     */
    private function firstWords(): ?string
    {
        preg_match_all(self::WORD, $this->text, $words, PREG_SET_ORDER);
        $length = 0;

        foreach ($words as $number => [$spaced, $word]) {
            // Without the space after it.
            $characters = mb_strlen(substr($this->text, 0, $length) . $word);
            $length += strlen($spaced);
            $next = substr($this->text, $length);

            if ($number + 1 < $this->firstWords || $characters < self::MIN_CHARACTERS) {
                continue;
            }

            if (preg_match('/\d/', $word) === 1 || preg_match('/^\S*\d/', $next) === 1) {
                continue;
            }

            if ($number > 0 && preg_match('/^\p{Lu}/u', $word) === 1 && preg_match('/^\p{Lu}/u', $next) === 1) {
                continue;
            }

            return substr($this->text, 0, $length);
        }

        return null;
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
