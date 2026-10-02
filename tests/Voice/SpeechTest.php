<?php

declare(strict_types=1);

namespace Tests\Voice;

use App\Voice\Speech;
use PHPUnit\Framework\TestCase;

use function React\Async\await;

final class SpeechTest extends TestCase
{
    public function testWritesTheSpokenTextToTheOutputFile(): void
    {
        $wavPath = sys_get_temp_dir() . '/speech-test-' . uniqid() . '.wav';
        $speech = new Speech(__DIR__ . '/../Fixtures/fake-piper', '/voices/en_US-lessac-medium.onnx');

        $this->assertSame($wavPath, await($speech->synthesize('Paris is the capital of France.', $wavPath)));
        $this->assertSame('Paris is the capital of France.', file_get_contents($wavPath));

        unlink($wavPath);
    }
}
