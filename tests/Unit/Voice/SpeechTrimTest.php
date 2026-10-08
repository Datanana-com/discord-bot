<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Voice\OggOpus;
use App\Voice\Speech;
use PHPUnit\Framework\TestCase;
use Tests\WaitsWithin;
use Tests\Wav;

/**
 * What the voice's files come out as once their silence is taken off: real ffmpeg, a stand-in for the voice that
 * says what its files hold, and the audio decoded again to see what is left. The levels are those of speech
 * measured in Piper's and Kokoro's own files: a loud vowel is about -20 dB, the soft start of an "s", an "h" or an
 * "f" about -50 dB, and the silence around a sentence about -75 dB.
 */
final class SpeechTrimTest extends TestCase
{
    use WaitsWithin;

    private const string FIXTURES = __DIR__ . '/../../Fixtures';

    /** Samples a second in the audio the tests decode. */
    private const int RATE = 48000;

    private string $directory;

    private ?Speech $speech = null;

    protected function setUp(): void
    {
        // Like VoiceCallTest: the bot itself needs ffmpeg with libopus, and so does this.
        if (trim((string) shell_exec('command -v ffmpeg')) === '' || ! str_contains((string) shell_exec('ffmpeg -hide_banner -encoders 2>&1'), 'libopus')) {
            $this->markTestSkipped('Needs ffmpeg with libopus.');
        }

        $this->directory = sys_get_temp_dir() . '/speech-trim-test-' . uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->speech !== null) {
                $this->within(10.0, $this->speech->stop(), 'the voice');
            }
        } finally {
            putenv('FAKE_PIPER_WAV');

            if (isset($this->directory)) {
                exec('rm -rf ' . escapeshellarg($this->directory));
            }
        }
    }

    public function testTakesTheSilenceOffBothEndsAndKeepsASoftStartAndEnd(): void
    {
        // 0.3 s of silence, a soft "s" of 80 ms, a vowel of 0.3 s, a soft ending of 120 ms, and 0.4 s of silence.
        $samples = $this->spoken([[0.3, -75.0, 'noise'], [0.08, -50.0, 'noise'], [0.3, -20.0, 'tone'], [0.12, -50.0, 'noise'], [0.4, -75.0, 'noise']]);

        $lead = $this->milliseconds($this->firstSound($samples));
        $trail = $this->milliseconds($this->firstSound(array_reverse($samples)));

        // The 0.7 s of silence is gone, but for a little: 20 ms before the first sound and 50 ms after the last.
        $this->assertLessThan(0.62, count($samples) / self::RATE, 'The silence was not taken off.');
        $this->assertLessThan(60, $lead, 'The silence before the first sound was not taken off.');
        $this->assertLessThan(120, $trail, 'The silence after the last sound was not taken off.');
        $this->assertGreaterThan(20, $trail, 'No silence is left after the last sound.');
        // The first and the last sound are the soft ones: a start that took them would be the vowel, a hundred times louder.
        $this->assertGreaterThan(0.5, count($samples) / self::RATE, 'The soft start or the soft end was cut off.');
        $this->assertEqualsWithDelta(-50.0, $this->level($samples, $this->firstSound($samples) + (int) (0.015 * self::RATE), 0.05), 3.0, 'The soft start is not whole.');
        $this->assertEqualsWithDelta(-50.0, $this->level(array_reverse($samples), $this->firstSound(array_reverse($samples)) + (int) (0.015 * self::RATE), 0.08), 3.0, 'The soft end is not whole.');
    }

    public function testKeepsAPieceOfTheSilenceBeforeTheFirstSound(): void
    {
        $samples = $this->spoken([[0.3, -75.0, 'noise'], [0.5, -20.0, 'tone'], [0.3, -75.0, 'noise']]);

        // The first packet leaves a little after the speaking flag does, and what a listener's end loses of the start
        // should be this, not the first sound.
        $this->assertGreaterThan(5, $this->milliseconds($this->firstSound($samples)), 'No silence is left before the first sound.');
    }

    public function testKeepsTheSilenceInsideASentence(): void
    {
        // A pause between two words is part of how it sounds; only the two ends are trimmed.
        $samples = $this->spoken([[0.2, -20.0, 'tone'], [0.3, -75.0, 'noise'], [0.2, -20.0, 'tone']]);

        $this->assertGreaterThan(0.7, count($samples) / self::RATE, 'The pause was cut.');
    }

    public function testASentenceOfNothingButSilenceStillHasAudio(): void
    {
        // What Kokoro's wrapper gives for a line it cannot speak. Without audio the player would send no packet at
        // all for it, and never log that the bot started speaking.
        putenv('FAKE_PIPER_WAV=' . $this->wav(Wav::silence(0.1, 22050)));
        $speech = $this->start();

        $ogg = $this->within(10.0, $speech->synthesize('...', "{$this->directory}/claude-1.ogg"), 'the voice');

        $packets = count(OggOpus::packets((string) file_get_contents($ogg)));
        $this->assertGreaterThanOrEqual(2, $packets);
        $this->assertLessThan(5, $packets);
    }

    public function testASentenceThatNeverGoesQuietKeepsItsLength(): void
    {
        // Nothing to take off: it is the one of fake-piper-tone, which VoiceCallTest counts the packets of.
        $samples = $this->spoken([[1.0, -20.0, 'tone']]);

        $this->assertEqualsWithDelta(1.0, count($samples) / self::RATE, 0.04);
    }

    /**
     * Has the voice speak a sentence made of these parts, and decodes the file the bot would play.
     *
     * @param list<array{0: float, 1: float, 2?: 'tone'|'noise'}> $parts
     * @return list<float> Mono samples, from -1 to 1.
     */
    private function spoken(array $parts): array
    {
        putenv('FAKE_PIPER_WAV=' . $this->wav(Wav::sounds($parts)));
        $ogg = $this->within(10.0, $this->start()->synthesize('Sure, the meeting starts at five.', "{$this->directory}/claude-1.ogg"), 'the voice');

        $pcm = (string) shell_exec('ffmpeg -loglevel error -i ' . escapeshellarg($ogg) . ' -f s16le -ac 1 -ar ' . self::RATE . ' -');
        $this->assertNotSame('', $pcm, 'The file does not decode.');

        return array_map(fn (int $value) => ($value >= 32768 ? $value - 65536 : $value) / 32768, array_values(unpack('v*', $pcm)));
    }

    private function start(): Speech
    {
        $this->speech = new Speech(self::FIXTURES . '/fake-piper-wav', '/voices/en_US-lessac-medium.onnx', 'ffmpeg');
        $this->speech->start("{$this->directory}/piper");

        return $this->speech;
    }

    private function wav(string $bytes): string
    {
        file_put_contents($path = "{$this->directory}/voice.wav", $bytes);

        return $path;
    }

    /**
     * The sample where the first sound starts: the first stretch of 2 ms, moved on by 1 ms, that is louder than
     * -58 dB. The silence around a sentence is at -75 dB, and the softest sound of a sentence at -50 dB.
     *
     * @param list<float> $samples
     */
    private function firstSound(array $samples): int
    {
        $window = (int) (0.002 * self::RATE);

        for ($from = 0; $from + $window <= count($samples); $from += 48) {
            if ($this->level($samples, $from, 0.002) > -58.0) {
                return $from;
            }
        }

        $this->fail('There is no sound in the file.');
    }

    /**
     * @param list<float> $samples
     * @return float The level, in dB below full scale, of the root mean square of that many seconds from there.
     */
    private function level(array $samples, int $from, float $seconds): float
    {
        $slice = array_slice($samples, $from, (int) ($seconds * self::RATE));

        return 10 * log10(max(1e-12, array_sum(array_map(fn (float $x) => $x * $x, $slice)) / max(1, count($slice))));
    }

    private function milliseconds(int $count): float
    {
        return $count / self::RATE * 1000;
    }
}
