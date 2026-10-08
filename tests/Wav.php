<?php

declare(strict_types=1);

namespace Tests;

/**
 * WAV files made by a test: silence, which is what whisper's decoder takes for audio, and speech-like sounds
 * with a known level, to see what a program does to their start and end. The header is right about what follows it.
 */
final class Wav
{
    public static function silence(float $seconds = 0.1, int $rate = 16000, int $channels = 1): string
    {
        return self::file(str_repeat("\0", (int) round($seconds * $rate) * $channels * 2), $rate, $channels);
    }

    /**
     * Mono audio made of parts, one after the other.
     *
     * A part is a pure tone, or noise: a soft "s", "h" or "f" is noise, not a tone, and what a trimmed start loses
     * of it is what these tests are about. Noise is the same every time.
     *
     * @param list<array{0: float, 1: float, 2?: 'tone'|'noise'}> $parts Seconds, level in dB below full scale as the
     *                                                                   root mean square of the part (-20.0 is loud
     *                                                                   speech, -50.0 a soft consonant, -75.0 the
     *                                                                   floor of a voice's silence), and the kind.
     */
    public static function sounds(array $parts, int $rate = 22050): string
    {
        $data = '';
        $seed = 7;

        foreach ($parts as $part) {
            $rms = 10 ** ($part[1] / 20);

            for ($i = 0, $samples = (int) round($part[0] * $rate); $i < $samples; $i++) {
                if (($part[2] ?? 'tone') === 'tone') {
                    $value = $rms * M_SQRT2 * sin(2 * M_PI * 300 * $i / $rate);
                } else {
                    // A small linear congruential generator, so that the noise is the same on every machine.
                    $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
                    // Uniform in -1 to 1 has a root mean square of 1 / sqrt(3).
                    $value = $rms * sqrt(3) * (2 * $seed / 0x7fffffff - 1);
                }

                $data .= pack('v', (int) round(max(-1.0, min(1.0, $value)) * 32767) & 0xffff);
            }
        }

        return self::file($data, $rate, 1);
    }

    private static function file(string $data, int $rate, int $channels): string
    {
        return pack('a4Va4a4VvvVVvva4V', 'RIFF', 36 + strlen($data), 'WAVE', 'fmt ', 16, 1, $channels, $rate, $rate * $channels * 2, $channels * 2, 16, 'data', strlen($data)) . $data;
    }
}
