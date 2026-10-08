<?php

declare(strict_types=1);

namespace App\Voice;

/**
 * What a voice is called when someone picks it from a list, as opposed to its name in a file or in .env.
 */
final class VoiceLabel
{
    /** The language a Kokoro voice speaks, from the first letter of its name. */
    private const array KOKORO_LANGUAGES = [
        'a' => 'American English',
        'b' => 'British English',
        'e' => 'Spanish',
        'f' => 'French',
        'h' => 'Hindi',
        'i' => 'Italian',
        'j' => 'Japanese',
        'p' => 'Brazilian Portuguese',
        'z' => 'Mandarin',
    ];

    /**
     * The voice's own name first, then what tells it from the others of that name:
     * "en_US-lessac-medium" is "Lessac (en_US, medium)" and "af_heart" is "Heart (American English, female)".
     * A name that is neither is its own label.
     */
    public static function of(string $voice): string
    {
        // Piper: language and region, the voice, how good it is. A voice can be in more than one quality.
        if (preg_match('/^([a-z]{2,3}_[A-Z]{2})-([\p{Ll}\p{N}_]+)-(x_low|low|medium|high)$/u', $voice, $piper) === 1) {
            return self::name($piper[2]) . " ({$piper[1]}, " . str_replace('_', ' ', $piper[3]) . ')';
        }

        // Kokoro: a letter for the language, a letter for female or male, the voice.
        if (preg_match('/^([a-z])([fm])_([a-z]+)$/', $voice, $kokoro) === 1 && isset(self::KOKORO_LANGUAGES[$kokoro[1]])) {
            return self::name($kokoro[3]) . ' (' . self::KOKORO_LANGUAGES[$kokoro[1]] . ', ' . ($kokoro[2] === 'f' ? 'female' : 'male') . ')';
        }

        return $voice;
    }

    private static function name(string $name): string
    {
        $name = str_replace('_', ' ', $name);

        // Not ucfirst(): a Piper voice can be named after someone whose name starts with a letter that has an accent.
        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }
}
