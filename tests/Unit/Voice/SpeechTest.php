<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Support\CommandFailedException;
use App\Voice\Speech;
use PHPUnit\Framework\TestCase;

use function React\Async\await;

final class SpeechTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures';

    private string $oggPath;

    protected function setUp(): void
    {
        $this->oggPath = sys_get_temp_dir() . '/speech-test-' . uniqid() . '.ogg';
    }

    protected function tearDown(): void
    {
        if (is_file($this->oggPath)) {
            unlink($this->oggPath);
        }
    }

    public function testWritesTheSpokenTextToTheOutputFile(): void
    {
        $speech = new Speech(self::FIXTURES . '/fake-piper', '/voices/en_US-lessac-medium.onnx', self::FIXTURES . '/fake-ffmpeg');

        $this->assertSame($this->oggPath, await($speech->synthesize('Paris is the capital of France.', $this->oggPath)));
        $this->assertSame('Paris is the capital of France.', file_get_contents($this->oggPath), "Piper's file was converted into it.");
        $this->assertFileDoesNotExist("{$this->oggPath}.piper.wav");
    }

    public function testRemovesPipersFileWhenItCannotBeConverted(): void
    {
        $speech = new Speech(self::FIXTURES . '/fake-piper', '/voices/en_US-lessac-medium.onnx', '/nowhere/ffmpeg');

        try {
            await($speech->synthesize('Paris is the capital of France.', $this->oggPath));
            $this->fail('Synthesizing should have failed.');
        } catch (CommandFailedException $e) {
            $this->assertStringStartsWith('/nowhere/ffmpeg exited with code 127', $e->getMessage());
        }

        $this->assertFileDoesNotExist("{$this->oggPath}.piper.wav");
        $this->assertFileDoesNotExist($this->oggPath);
    }
}
