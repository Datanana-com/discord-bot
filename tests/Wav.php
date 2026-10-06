<?php

declare(strict_types=1);

namespace Tests;

/**
 * A WAV file of nothing but silence, which is what whisper's decoder takes for audio: a header that is right about
 * what follows it.
 */
final class Wav
{
    public static function silence(float $seconds = 0.1, int $rate = 16000, int $channels = 1): string
    {
        $data = str_repeat("\0", (int) round($seconds * $rate) * $channels * 2);

        return pack('a4Va4a4VvvVVvva4V', 'RIFF', 36 + strlen($data), 'WAVE', 'fmt ', 16, 1, $channels, $rate, $rate * $channels * 2, $channels * 2, 16, 'data', strlen($data)) . $data;
    }
}
