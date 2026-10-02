<?php

declare(strict_types=1);

namespace Tests\Voice;

use App\Voice\Transcriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function React\Async\await;

final class TranscriberTest extends TestCase
{
    public function testRunsWhisperAndCleansItsOutput(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'fake-whisper-');
        putenv("FAKE_WHISPER_LOG={$log}");

        $transcriber = new Transcriber(__DIR__ . '/../Fixtures/fake-whisper', '/models/ggml-base.bin', 'auto');
        $text = await($transcriber->transcribe('/recordings/utterance-1.wav'));

        $this->assertSame('Hey Claude, what time is it?', $text);
        $this->assertSame(
            "arg=--model\narg=/models/ggml-base.bin\narg=--language\narg=auto\narg=--no-timestamps\narg=--no-prints\narg=--file\narg=/recordings/utterance-1.wav\n",
            file_get_contents($log),
        );

        putenv('FAKE_WHISPER_LOG');
        unlink($log);
    }

    #[DataProvider('outputs')]
    public function testClean(string $output, string $expected): void
    {
        $this->assertSame($expected, Transcriber::clean($output));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function outputs(): iterable
    {
        yield 'silence' => [" [BLANK_AUDIO]\n", ''];
        yield 'sounds only' => [' (keyboard clicking) [MUSIC]', ''];
        yield 'speech over lines' => [" Hello there.\n How are you?\n", 'Hello there. How are you?'];
        yield 'speech with annotations' => [' [laughs] That is funny (coughs) indeed.', 'That is funny indeed.'];
    }
}
