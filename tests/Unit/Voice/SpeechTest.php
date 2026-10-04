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

    private string $voices;

    protected function setUp(): void
    {
        $this->oggPath = sys_get_temp_dir() . '/speech-test-' . uniqid() . '.ogg';
        // Brackets mean something in a glob pattern, and nothing in a folder's name.
        $this->voices = sys_get_temp_dir() . '/speech-test-voices [' . uniqid() . ']';
    }

    protected function tearDown(): void
    {
        unset($_ENV['PIPER_MODEL']);

        if (is_file($this->oggPath)) {
            unlink($this->oggPath);
        }

        if (is_dir($this->voices)) {
            array_map(fn (string $file) => unlink("{$this->voices}/{$file}"), array_diff(scandir($this->voices), ['.', '..']));
            rmdir($this->voices);
        }
    }

    public function testAServersVoiceIsInTheSameFolderAsTheOneInEnv(): void
    {
        $_ENV['PIPER_MODEL'] = '/piper/voices/en_US-lessac-medium.onnx';

        $this->assertSame('/piper/voices/en_US-lessac-medium.onnx', Speech::fromEnv()->model);
        $this->assertSame('/piper/voices/pt_BR-faber-medium.onnx', Speech::fromEnv('pt_BR-faber-medium')->model);
    }

    public function testListsTheVoicesInstalledNextToTheOneInEnv(): void
    {
        mkdir($this->voices);

        // Piper keeps each voice's settings in a .onnx.json file next to it.
        foreach (['pt_BR-faber-medium.onnx', 'pt_BR-faber-medium.onnx.json', 'en_US-lessac-medium.onnx', 'en_US-lessac-medium.onnx.json', 'README.txt'] as $file) {
            touch("{$this->voices}/{$file}");
        }

        $_ENV['PIPER_MODEL'] = "{$this->voices}/en_US-lessac-medium.onnx";

        $this->assertSame(['en_US-lessac-medium', 'pt_BR-faber-medium'], Speech::voices());
    }

    public function testListsNoVoicesWhenTheFolderDoesNotExist(): void
    {
        $_ENV['PIPER_MODEL'] = '/nowhere/voices/en_US-lessac-medium.onnx';

        $this->assertSame([], Speech::voices());

        // Nor without a voice in .env: there is no folder to look in.
        unset($_ENV['PIPER_MODEL']);

        $this->assertSame([], Speech::voices());
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
