<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Support\CommandFailedException;
use App\Voice\Transcriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function React\Async\await;

final class TranscriberTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['WHISPER_LANGUAGE'], $_ENV['WHISPER_PROMPT']);
    }

    public function testAServersLanguageReplacesTheOneInEnv(): void
    {
        $this->assertSame('auto', Transcriber::fromEnv()->language);

        $_ENV['WHISPER_LANGUAGE'] = 'en';

        $this->assertSame('en', Transcriber::fromEnv()->language);
        $this->assertSame('pt', Transcriber::fromEnv('pt')->language);
    }

    public function testKnowsTheLanguagesWhisperTranscribes(): void
    {
        // The codes of whisper.cpp's language table (g_lang in src/whisper.cpp).
        $this->assertCount(100, Transcriber::LANGUAGES);
        $this->assertSame(Transcriber::LANGUAGES, array_unique(Transcriber::LANGUAGES));
        $this->assertContains('en', Transcriber::LANGUAGES);
        $this->assertContains('pt', Transcriber::LANGUAGES);
        $this->assertContains('yue', Transcriber::LANGUAGES);
        $this->assertNotContains('auto', Transcriber::LANGUAGES, 'Detecting the language is not a language.');

        foreach (Transcriber::LANGUAGES as $code) {
            $this->assertMatchesRegularExpression('/^[a-z]{2,3}$/', $code);
        }
    }

    public function testRunsWhisperAndCleansItsOutput(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'fake-whisper-');
        putenv("FAKE_WHISPER_LOG={$log}");

        $transcriber = new Transcriber(__DIR__ . '/../../Fixtures/fake-whisper', '/models/ggml-base.bin', 'auto');
        $text = await($transcriber->transcribe('/recordings/utterance-1.wav'));

        $this->assertSame('Hey Claude, what time is it?', $text);
        $this->assertSame(
            "arg=--model\narg=/models/ggml-base.bin\narg=--language\narg=auto\narg=--no-timestamps\narg=--no-prints\narg=--file\narg=/recordings/utterance-1.wav\n",
            file_get_contents($log),
        );

        putenv('FAKE_WHISPER_LOG');
        unlink($log);
    }

    public function testKillsWhisperWhenItTakesLongerThanTheTimeout(): void
    {
        putenv('FAKE_WHISPER_DELAY=1');

        try {
            await($this->slowTranscriber()->transcribe('/recordings/utterance-1.wav'));
            $this->fail('whisper should have timed out.');
        } catch (CommandFailedException $e) {
            $this->assertStringContainsString('fake-whisper timed out after 0.2s', $e->getMessage());
        } finally {
            putenv('FAKE_WHISPER_DELAY');
        }
    }

    public function testGivesWhisperTimeForTheLengthOfTheAudio(): void
    {
        putenv('FAKE_WHISPER_DELAY=1');

        try {
            // The same whisper that timed out after 0.2 seconds has 3 seconds for each second of audio, so it has 3 here.
            $this->assertSame('Hey Claude, what time is it?', await($this->slowTranscriber()->transcribe('/recordings/utterance-1.wav', seconds: 1.0)));
        } finally {
            putenv('FAKE_WHISPER_DELAY');
        }
    }

    public function testDefaultsToTwoMinutesForAudioOfUnknownLength(): void
    {
        $this->assertSame(120.0, (new Transcriber('whisper-cli', '/models/ggml-base.bin', 'auto'))->minimumTimeout);
        $this->assertSame(120.0, Transcriber::fromEnv()->minimumTimeout);
        $this->assertSame(3.0, Transcriber::SECONDS_PER_SECOND_OF_AUDIO);
    }

    public function testPassesTheWhisperPromptToWhisper(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'fake-whisper-pr23-');
        putenv("FAKE_WHISPER_LOG={$log}");

        $transcriber = new Transcriber(__DIR__ . '/../../Fixtures/fake-whisper', '/models/ggml-base.bin', 'en', 'A voice call with the assistant Claude.');
        await($transcriber->transcribe('/recordings/utterance-1.wav'));

        $this->assertSame(
            "arg=--model\narg=/models/ggml-base.bin\narg=--language\narg=en\narg=--prompt\narg=A voice call with the assistant Claude.\narg=--no-timestamps\narg=--no-prints\narg=--file\narg=/recordings/utterance-1.wav\n",
            file_get_contents($log),
        );

        putenv('FAKE_WHISPER_LOG');
        unlink($log);
    }

    public function testReadsThePromptFromTheEnvironment(): void
    {
        $_ENV['WHISPER_PROMPT'] = '  Hey Claude.  ';
        $this->assertSame('Hey Claude.', Transcriber::fromEnv()->prompt);

        $_ENV['WHISPER_PROMPT'] = '';
        $this->assertSame('', Transcriber::fromEnv()->prompt, 'Empty means no prompt.');

        unset($_ENV['WHISPER_PROMPT']);
        $this->assertSame('', Transcriber::fromEnv()->prompt, 'So does not setting it.');
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

    private function slowTranscriber(): Transcriber
    {
        return new Transcriber(__DIR__ . '/../../Fixtures/fake-whisper', '/models/ggml-base.bin', 'auto', minimumTimeout: 0.2);
    }
}
