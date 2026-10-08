<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Voice\Speech;
use PHPUnit\Framework\TestCase;
use Tests\WaitsWithin;

/**
 * The program PIPER_BINARY points at to speak with Kokoro (kokoro/kokoro_serve.py), as the bot starts it, with the
 * engine that needs torch and the model replaced by a stand-in (tests/Fixtures/kokoro): what it prints for a
 * sentence, for an empty line, for one it can't speak, and when it ends. It is not a PHP file: what it says is
 * what Speech.php reads, so Speech is the other side of every test here.
 */
final class KokoroProgramTest extends TestCase
{
    use WaitsWithin;

    private const string PROGRAM = __DIR__ . '/../../Fixtures/fake-kokoro';

    private string $directory;

    private ?Speech $speech = null;

    protected function setUp(): void
    {
        if (trim((string) shell_exec('command -v python3')) === '') {
            $this->markTestSkipped('Needs python3.');
        }

        $this->directory = sys_get_temp_dir() . '/kokoro-program-test-' . uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->speech !== null) {
                $this->within(10.0, $this->speech->stop(), 'the voice');
            }
        } finally {
            unset($_ENV['PIPER_MODEL']);
            putenv('FAKE_KOKORO_LOG');
            putenv('TTS_QUIET');

            if (isset($this->directory)) {
                exec('rm -rf ' . escapeshellarg($this->directory));
            }
        }
    }

    public function testAnswersEveryLineWithAWavFileInTheOrderTheyCameAndEndsWhenItsInputDoes(): void
    {
        $lines = ['Hello there, my dear friend.', '', '?!...', 'Another sentence, with six words here.'];

        $run = $this->runProgram($lines, ['--model', '/voices/af_heart.onnx', '--output-dir', "{$this->directory}/out"]);

        $this->assertSame(0, $run['code'], 'It ends by itself, with no error, when its input is closed.');
        $this->assertSame('', $run['stdout'], 'Nothing is written to stdout.');
        $this->assertSame(
            array_map(fn (int $n) => "INFO:__main__:Wrote {$this->directory}/out/{$n}.wav", [1, 2, 3, 4]),
            array_values(array_filter($run['stderr'], fn (string $line) => str_starts_with($line, 'INFO:__main__:Wrote '))),
            'One "Wrote" line for every line it got, also for the ones with nothing to say, in the order they came.',
        );

        // The sentence is spoken: five words of 0.1 s, as WAV Speech.php hands to ffmpeg.
        $first = $this->wav("{$this->directory}/out/1.wav");
        $this->assertSame([1, 24000, 16], [$first['channels'], $first['rate'], $first['bits']]);
        $this->assertEqualsWithDelta(0.5, $first['seconds'], 0.001);
        $this->assertNotSame(str_repeat("\0", strlen($first['data'])), $first['data'], 'The sentence is not silence.');
        $this->assertEqualsWithDelta(0.6, $this->wav("{$this->directory}/out/4.wav")['seconds'], 0.001);

        // Nothing to say, but answered: 0.1 s of silence keeps one line in, one "Wrote" out.
        foreach ([2, 3] as $n) {
            $silence = $this->wav("{$this->directory}/out/{$n}.wav");
            $this->assertEqualsWithDelta(0.1, $silence['seconds'], 0.001, "Line {$n} has nothing to say.");
            $this->assertSame(str_repeat("\0", strlen($silence['data'])), $silence['data'], "Line {$n} is silence.");
        }
    }

    public function testSaysWhichDeviceItRunsOnFirstAndThatItIsReadyBeforeItSpeaks(): void
    {
        $run = $this->runProgram(['Hello there.'], ['--model', '/voices/af_bella.onnx', '--output-dir', "{$this->directory}/out"]);

        // The first thing it says, before the model loads, so that whoever starts it knows even when the load never ends.
        $this->assertSame('DEVICE cpu', $run['stderr'][0]);
        $this->assertStringStartsWith('READY {', $run['stderr'][1]);
        $this->assertSame(['voice' => 'af_bella', 'device' => 'cpu'], json_decode(substr($run['stderr'][1], 6), true), 'The voice is the name of the file given as the model.');
        $this->assertMatchesRegularExpression('/^FIRST \d+\.\d AUDIO 0\.200$/', $run['stderr'][2]);
        $this->assertStringStartsWith('INFO:__main__:Wrote ', $run['stderr'][3]);
        $this->assertCount(4, $run['stderr']);

        $run = $this->runProgram([], ['--device', 'cuda', '--output-dir', "{$this->directory}/out"], ['FAKE_KOKORO_LOG' => "{$this->directory}/loaded.log"]);

        // The card is named, and only its kind is what the model is loaded onto.
        $this->assertSame('DEVICE cuda (Stand-in card)', $run['stderr'][0], 'A device that was asked for is the one it uses.');
        $this->assertStringStartsWith('voice=af_heart device=cuda offline=', (string) file_get_contents("{$this->directory}/loaded.log"));
    }

    public function testReadsTheModelFromItsOwnFolderAndNeverFromTheInternet(): void
    {
        $this->runProgram(['Hello there.'], ['--output-dir', "{$this->directory}/out"], ['FAKE_KOKORO_LOG' => "{$this->directory}/loaded.log"]);

        // Where the program is: kokoro/hf, which install.sh fills. A missing file is an error at once, not a wait for the network.
        $this->assertSame(
            ' offline=1 home=' . realpath(__DIR__ . '/../../../kokoro') . "/hf\n",
            substr((string) file_get_contents("{$this->directory}/loaded.log"), strlen('voice=af_heart device=cpu')),
        );
    }

    public function testLeavesOutReadyAndFirstWhenToldToBeQuiet(): void
    {
        $run = $this->runProgram(['Hello there.'], ['--model', '/voices/af_heart.onnx', '--output-dir', "{$this->directory}/out"], ['TTS_QUIET' => '1']);

        $this->assertSame(['DEVICE cpu', "INFO:__main__:Wrote {$this->directory}/out/1.wav"], $run['stderr']);
    }

    public function testFailsWithTheReasonOnStderrWhenTheVoiceCannotSpeakALine(): void
    {
        $run = $this->runProgram(['Fine so far.', 'This one will FAIL.', 'Never reached.'], ['--model', '/voices/af_heart.onnx', '--output-dir', "{$this->directory}/out"]);

        $this->assertNotSame(0, $run['code']);
        $this->assertStringContainsString('The voice could not speak that.', implode("\n", $run['stderr']));
        $this->assertSame(1, count(array_filter($run['stderr'], fn (string $line) => str_starts_with($line, 'INFO:__main__:Wrote '))), 'Only the sentence before it was written.');
        $this->assertFileDoesNotExist("{$this->directory}/out/2.wav");
        $this->assertSame('', $run['stdout']);
    }

    public function testASentenceGoesThroughSpeechAsItDoesWithPiper(): void
    {
        // PIPER_MODEL is a file next to the others: Kokoro's voices are the empty .onnx files install.sh makes.
        mkdir("{$this->directory}/voices");
        touch("{$this->directory}/voices/af_heart.onnx");
        touch("{$this->directory}/voices/af_bella.onnx");
        $_ENV['PIPER_MODEL'] = "{$this->directory}/voices/af_heart.onnx";
        putenv("FAKE_KOKORO_LOG={$this->directory}/loaded.log");

        $this->assertSame(['af_bella', 'af_heart'], Speech::voices(), 'A server can choose any of them with /settings.');

        $this->speech = new Speech(self::PROGRAM, "{$this->directory}/voices/af_bella.onnx", 'ffmpeg');
        $this->speech->start("{$this->directory}/kokoro");
        $ogg = $this->within(10.0, $this->speech->synthesize('Hello there, my dear friend.', "{$this->directory}/claude-1.ogg"), 'the voice');

        $this->assertFileExists($ogg);
        $this->assertStringStartsWith('voice=af_bella device=cpu ', (string) file_get_contents("{$this->directory}/loaded.log"), 'The voice a server chose is the one that was loaded.');
        // The program's own words on stderr are not what Speech takes for the end of a sentence.
        $this->assertSame(['.', '..'], scandir("{$this->directory}/kokoro"));
    }

    /**
     * Starts the program like Speech does, writes the lines and closes its input.
     *
     * @param list<string> $lines
     * @param list<string> $arguments
     * @param array<string, string> $env
     * @return array{code: int, stdout: string, stderr: list<string>}
     */
    private function runProgram(array $lines, array $arguments, array $env = []): array
    {
        $process = proc_open([self::PROGRAM, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env === [] ? null : [...getenv(), ...$env]);
        $this->assertIsResource($process);

        // A program that fails before it reads has closed its input: the write is then no failure of the test.
        @fwrite($pipes[0], implode('', array_map(fn (string $line) => "{$line}\n", $lines)));
        fclose($pipes[0]);

        $stdout = $stderr = '';
        $deadline = microtime(true) + 20.0;

        while (! feof($pipes[1]) || ! feof($pipes[2])) {
            $read = array_filter([$pipes[1], $pipes[2]], fn ($pipe) => ! feof($pipe));
            $write = $except = null;

            if (microtime(true) > $deadline) {
                proc_terminate($process, SIGKILL);
                $this->fail('The program did not end when its input was closed.');
            }

            if (stream_select($read, $write, $except, 1) > 0) {
                foreach ($read as $pipe) {
                    $chunk = (string) fread($pipe, 8192);

                    if ($pipe === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr === '' ? [] : explode("\n", rtrim($stderr, "\n"))];
    }

    /**
     * @return array{channels: int, rate: int, bits: int, seconds: float, data: string}
     */
    private function wav(string $path): array
    {
        $this->assertFileExists($path);
        $bytes = (string) file_get_contents($path);
        $this->assertSame('RIFF', substr($bytes, 0, 4), "{$path} is a WAV file.");
        $header = unpack('vformat/vchannels/Vrate/Vbytes/vblock/vbits', substr($bytes, 20, 16));
        $this->assertSame(1, $header['format'], 'Plain PCM, which ffmpeg and Piper agree on.');
        $data = substr($bytes, 44);

        return ['channels' => $header['channels'], 'rate' => $header['rate'], 'bits' => $header['bits'], 'seconds' => strlen($data) / $header['bytes'], 'data' => $data];
    }
}
