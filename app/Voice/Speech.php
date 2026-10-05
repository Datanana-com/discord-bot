<?php

declare(strict_types=1);

namespace App\Voice;

use App\Support\CommandFailedException;
use App\Support\Program;
use App\Support\Shell;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Text-to-speech through a local Piper install.
 *
 * Piper keeps running for a whole call, so that its voice is loaded once and not for every sentence:
 * start() starts it, and stop() ends it. It also ends with the bot, as its stdin is closed then.
 *
 * @see https://github.com/OHF-Voice/piper1-gpl
 */
final class Speech
{
    /** The Piper process, while it runs. */
    private ?Program $piper = null;

    /** Where Piper writes the WAV file of each sentence. */
    private string $folder = '';

    /** @var list<Deferred<string>> The sentences Piper has not spoken yet, in the order it got them. Each resolves with its WAV file. */
    private array $sentences = [];

    /**
     * @param float $timeout Seconds Piper has to speak a sentence, before it is stopped.
     */
    public function __construct(
        public readonly string $binary,
        public readonly string $model,
        public readonly string $ffmpeg = 'ffmpeg',
        private readonly float $timeout = 120.0,
    ) {
    }

    /**
     * @param string|null $voice The name of the voice that speaks, when it is not the one in .env: one of voices().
     */
    public static function fromEnv(?string $voice = null): self
    {
        $model = env('PIPER_MODEL', '');

        return new self(
            env('PIPER_BINARY', 'piper'),
            $voice === null ? $model : dirname($model) . "/{$voice}.onnx",
            env('FFMPEG_BINARY', 'ffmpeg'),
        );
    }

    /**
     * The names of the installed voices: those in the same folder as the one in .env.
     * The voice in en_US-lessac-medium.onnx is called "en_US-lessac-medium".
     *
     * @return list<string>
     */
    public static function voices(): array
    {
        // Not glob(): a folder's name can have characters that mean something in a pattern.
        $folder = dirname(env('PIPER_MODEL', ''));
        $files = is_dir($folder) ? scandir($folder) : [];

        return array_values(array_map(
            fn (string $file) => basename($file, '.onnx'),
            array_filter($files, fn (string $file) => str_ends_with($file, '.onnx')),
        ));
    }

    /**
     * Starts Piper, which loads the voice: the sentences then only wait for their own speech.
     *
     * @param string $folder Where Piper writes the WAV file of each sentence, until it is converted: a folder
     *                       of its own, which is emptied and removed when Piper ends.
     */
    public function start(string $folder): void
    {
        if ($this->piper !== null) {
            return;
        }

        $this->folder = $folder;
        // With --output-dir, Piper keeps reading its stdin: it speaks each line into a WAV file of the folder,
        // and says on stderr when the file is complete.
        $this->piper = Shell::open([$this->binary, '--model', $this->model, '--output-dir', $folder], onErrorLine: $this->wrote(...));
        $this->piper->done()
            ->then(fn () => new CommandFailedException("{$this->binary} ended before it spoke the sentence", ''), fn (Throwable $e) => $e)
            ->then($this->ended(...));
    }

    /**
     * Whether Piper is running. It isn't once it stopped by itself, as when a sentence made it fail:
     * the next sentence starts it again.
     */
    public function isRunning(): bool
    {
        return $this->piper !== null;
    }

    /**
     * Ends Piper, once it has spoken the sentences it already got.
     *
     * @return PromiseInterface<mixed> Resolves once it has ended, and its folder is gone. It never rejects.
     */
    public function stop(): PromiseInterface
    {
        if ($this->piper === null) {
            return resolve(null);
        }

        $this->piper->end(timeout: $this->timeout);

        return $this->piper->done()->catch(static fn () => null);
    }

    /**
     * Speaks the text into an Ogg Opus file. Piper is started when it isn't running, in the folder it had before.
     *
     * Piper writes WAV files, but the voice library plays files through ffmpeg with
     * -fflags +nobuffer, which drops whatever ffmpeg reads while probing the file: all of
     * a short Piper WAV, yet only the first 20 ms of an Ogg Opus file. So Piper's WAV is
     * converted.
     *
     * @return PromiseInterface<string> The path of the written file. Rejects when there is nothing to say in the
     *                                  text, when Piper ends before it spoke it, or takes too long, and when its
     *                                  speech can't be converted.
     */
    public function synthesize(string $text, string $oggPath): PromiseInterface
    {
        // Piper speaks each line it reads on its own, so the text is one line. And it skips a line that holds
        // nothing but whitespace, of any script, without a word: it must never get one, or every sentence after
        // it would get the speech of the one before.
        $line = trim(preg_replace('/[\s\x{1c}-\x{1f}]+/u', ' ', mb_scrub($text)));

        if ($line === '') {
            return reject(new RuntimeException('There is nothing to say in the sentence.'));
        }

        $piperPath = "{$oggPath}.piper.wav";
        // Before Piper is started: when it can't be, it has ended at once, and this sentence with it.
        $this->sentences[] = $spoken = new Deferred();
        $this->start($this->folder);
        $piper = $this->piper;
        $timer = Loop::addTimer($this->timeout, fn () => $piper?->stop("timed out after {$this->timeout}s"));

        $piper?->write("{$line}\n");

        return $spoken->promise()
            ->finally(fn () => Loop::cancelTimer($timer))
            // Out of Piper's folder, which is emptied when Piper ends.
            ->then(fn (string $wavPath) => rename($wavPath, $piperPath))
            ->then(fn () => Shell::run([$this->ffmpeg, '-loglevel', 'error', '-y', '-i', $piperPath, '-c:a', 'libopus', $oggPath]))
            ->finally(fn () => is_file($piperPath) && unlink($piperPath))
            ->then(fn () => $oggPath);
    }

    /**
     * Reads a line Piper printed on stderr: "INFO:__main__:Wrote <path>" once the WAV file of a sentence is complete.
     *
     * @return bool Whether it was that, and not something that may say why Piper fails.
     */
    private function wrote(string $line): bool
    {
        if (preg_match('/^INFO:__main__:Wrote (.+)$/', $line, $match) !== 1) {
            return false;
        }

        array_shift($this->sentences)?->resolve($match[1]);

        return true;
    }

    /**
     * Piper has ended: the sentences it had not spoken never will be.
     */
    private function ended(Throwable $how): void
    {
        $this->piper = null;

        // The file of a sentence it failed on, which it never said it wrote.
        foreach (is_dir($this->folder) ? scandir($this->folder) : [] as $file) {
            is_file("{$this->folder}/{$file}") && unlink("{$this->folder}/{$file}");
        }

        @rmdir($this->folder);

        foreach (array_splice($this->sentences, 0) as $sentence) {
            $sentence->reject($how);
        }
    }
}
