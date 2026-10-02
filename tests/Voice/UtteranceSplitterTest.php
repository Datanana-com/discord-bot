<?php

declare(strict_types=1);

namespace Tests\Voice;

use App\Voice\UtteranceSplitter;
use PHPUnit\Framework\TestCase;

final class UtteranceSplitterTest extends TestCase
{
    /** One 20 ms Opus frame decoded to 48 kHz, 16-bit stereo PCM. */
    private const int FRAME_BYTES = 3840;

    private string $directory;

    /** @var list<array{string, string}> */
    private array $utterances = [];

    private UtteranceSplitter $splitter;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/splitter-test-' . uniqid();
        $this->splitter = new UtteranceSplitter($this->directory, function (string $userId, string $wavPath) {
            $this->utterances[] = [$userId, $wavPath];
        });
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob("{$this->directory}/*"));
        @rmdir($this->directory);
    }

    public function testFinishesAnUtteranceAfterASilence(): void
    {
        $this->speak('alice', from: 0.0, seconds: 1.0);

        $this->splitter->flushSilent(1.5);
        $this->assertSame([], $this->utterances, 'Half a second of silence is just a pause.');

        $this->splitter->flushSilent(2.0);
        $this->assertCount(1, $this->utterances);

        [$userId, $wavPath] = $this->utterances[0];
        $this->assertSame('alice', $userId);
        $this->assertValidWav($wavPath, seconds: 1.0);
    }

    public function testKeepsEachSpeakerSeparate(): void
    {
        $this->speak('alice', from: 0.0, seconds: 1.0);
        $this->speak('bob', from: 0.5, seconds: 2.0);

        $this->splitter->flushSilent(2.0);
        $this->assertSame(['alice'], array_column($this->utterances, 0));

        $this->splitter->flushSilent(3.5);
        $this->assertSame(['alice', 'bob'], array_column($this->utterances, 0));
        $this->assertValidWav($this->utterances[1][1], seconds: 2.0);
    }

    public function testDropsUtterancesThatAreTooShort(): void
    {
        $this->speak('alice', from: 0.0, seconds: 0.2);

        $this->splitter->flushSilent(5.0);

        $this->assertSame([], $this->utterances);
        $this->assertSame([], glob("{$this->directory}/*.wav"), 'The short recording is deleted.');
    }

    public function testCutsUtterancesThatAreTooLong(): void
    {
        $this->speak('alice', from: 0.0, seconds: 31.0);

        $this->assertCount(1, $this->utterances, 'The first 30 seconds are handled without waiting for a pause.');
        $this->assertValidWav($this->utterances[0][1], seconds: 30.0);

        $this->splitter->flushSilent(40.0);
        $this->assertCount(2, $this->utterances);
        $this->assertValidWav($this->utterances[1][1], seconds: 1.0);
    }

    public function testFlushAllFinishesEveryUtteranceInProgress(): void
    {
        $this->speak('alice', from: 0.0, seconds: 1.0);
        $this->speak('bob', from: 0.0, seconds: 1.0);

        $this->splitter->flushAll();

        $this->assertSame(['alice', 'bob'], array_column($this->utterances, 0));
    }

    /**
     * Pushes 20 ms frames of audio, as the voice client does while someone talks.
     */
    private function speak(string $userId, float $from, float $seconds): void
    {
        for ($frame = 0; $frame < $seconds * 50; $frame++) {
            $this->splitter->push($userId, str_repeat("\x01\x00", self::FRAME_BYTES / 2), $from + $frame * 0.02);
        }
    }

    private function assertValidWav(string $path, float $seconds): void
    {
        $wav = file_get_contents($path);
        $header = unpack('a4riff/Vsize/a4wave/a4fmt/Vfmtsize/vformat/vchannels/Vrate/Vbyterate/vblock/vbits/a4data/Vdatasize', $wav);

        $this->assertSame('RIFF', $header['riff']);
        $this->assertSame('WAVE', $header['wave']);
        $this->assertSame(2, $header['channels']);
        $this->assertSame(48000, $header['rate']);
        $this->assertSame(16, $header['bits']);
        $this->assertSame((int) round($seconds * 48000 * 4), $header['datasize']);
        $this->assertSame(44 + $header['datasize'], strlen($wav));
    }
}
