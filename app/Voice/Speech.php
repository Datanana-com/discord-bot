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

use function React\Promise\all;
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
    /**
     * What ffmpeg does to a sentence's audio before it is encoded: it takes the silence off the start and the end.
     * Piper's files begin with up to 80 ms of it and end with up to 200 ms, Kokoro's with about 300 ms and end
     * with about 500 ms, and the start is time before every answer that nobody hears anything in.
     *
     * - Both ends are cut at -60 dB, with 20 ms of the silence kept before the first sound and 50 ms after the
     *   last. At -45 dB the filter also took the first 40 ms of a soft "s", "h" or "f" and the last of a soft "v"
     *   (what it cut reached -36 dB); at -60 dB nothing it cuts is above -56 dB, which no one hears.
     * - silenceremove only trims the start (its stop_periods would also drop the pauses inside the sentence), so
     *   the end is trimmed by reversing the audio, trimming its start, and reversing it back.
     * - apad makes the audio at least 40 ms long: a sentence of nothing but silence, which Kokoro's wrapper
     *   gives for a line it can't speak, would otherwise end up with no packets at all, and never "start" speaking.
     */
    private const string TRIM = 'silenceremove=start_periods=1:start_threshold=-60dB:start_silence=0.02,areverse,silenceremove=start_periods=1:start_threshold=-60dB:start_silence=0.05,areverse,apad=whole_dur=0.04';

    /** The Piper process, while it runs. */
    private ?Program $piper = null;

    /** Where Piper writes the WAV file of each sentence. */
    private string $folder = '';

    /** @var list<Deferred<string>> The sentences Piper has not spoken yet, in the order it got them. Each resolves with its WAV file. */
    private array $sentences = [];

    /** The ffmpeg that waits for the speech of the next sentence: see {@see Encoder}. */
    private ?Encoder $waiting = null;

    /** @var array<int, Encoder> Every ffmpeg that has not ended: the one that waits, and those that encode a sentence. */
    private array $encoders = [];

    /** Whether stop() was called: no ffmpeg is started ahead for a sentence that is not coming. */
    private bool $stopping = false;

    /**
     * @param float $timeout Seconds Piper has to speak a sentence, and ffmpeg to encode it, before it is stopped.
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
        $this->prepare();
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
     * Whether an ffmpeg waits for the speech of the next sentence. None does once it stopped by itself: the
     * next sentence starts one again.
     */
    public function isReadyToEncode(): bool
    {
        return $this->waiting?->isRunning() === true;
    }

    /**
     * Ends Piper, once it has spoken the sentences it already got, and the ffmpeg that waited for the next one.
     *
     * @return PromiseInterface<mixed> Resolves once Piper has ended, its folder is gone, and what it spoke is
     *                                 encoded. It never rejects.
     */
    public function stop(): PromiseInterface
    {
        $this->stopping = true;
        $this->waiting?->stop();
        $this->piper?->end(timeout: $this->timeout);

        return ($this->piper?->done() ?? resolve(null))
            ->catch(static fn () => null)
            // Those that encode a sentence end by themselves, also the one of a sentence Piper was still speaking.
            ->then(fn () => all(array_map(static fn (Encoder $encoder) => $encoder->done()->catch(static fn () => null), $this->encoders)))
            ->then(static fn () => null);
    }

    /**
     * Has Piper speak the text, and an ffmpeg encode its speech as Ogg Opus. Piper is started when it isn't
     * running, in the folder it had before.
     *
     * Piper is free for the next sentence as soon as it has written this one's WAV file. That file then goes to
     * the ffmpeg that was started ahead and waits for it (see {@see Encoder}), and the sentence can be played
     * from the first of what that ffmpeg writes: the three are told apart by the {@see Sentence} this gives back.
     *
     * With VOICE_PLAYER=bot the Opus packets go to Discord as they are, one every 20 ms, so each must hold
     * 20 ms: libopus's default frame duration. The whole stream is also written to $oggPath, which is what
     * VOICE_PLAYER=library plays: the voice library plays files through ffmpeg with -fflags +nobuffer, which
     * drops whatever ffmpeg reads while probing the file: all of a short Piper WAV, yet only the first 20 ms of
     * an Ogg Opus file.
     *
     * The silence at the start and the end of the speech is taken off in the same step: see TRIM.
     *
     * @return Sentence It fails when there is nothing to say in the text, when Piper ends before it spoke it, or
     *                  takes too long, and when its speech can't be encoded.
     */
    public function synthesize(string $text, string $oggPath): Sentence
    {
        // Piper speaks each line it reads on its own, so the text is one line. And it skips a line that holds
        // nothing but whitespace, of any script, without a word: it must never get one, or every sentence after
        // it would get the speech of the one before.
        $line = trim(preg_replace('/[\s\x{1c}-\x{1f}]+/u', ' ', mb_scrub($text)));

        if ($line === '') {
            $nothing = new RuntimeException('There is nothing to say in the sentence.');
            $sentence = new Sentence($oggPath, reject($nothing));
            $sentence->fail($nothing);

            return $sentence;
        }

        // Before Piper is started: when it can't be, it has ended at once, and this sentence with it.
        $this->sentences[] = $spoken = new Deferred();
        $this->start($this->folder);
        $piper = $this->piper;
        $timer = Loop::addTimer($this->timeout, fn () => $piper?->stop("timed out after {$this->timeout}s"));

        $piper?->write("{$line}\n");

        $sentence = null;
        $voiced = $spoken->promise()
            ->finally(fn () => Loop::cancelTimer($timer))
            ->then(function (string $wavPath) use (&$sentence): void {
                // Read now, out of Piper's folder, which is emptied when Piper ends.
                $wav = (string) file_get_contents($wavPath);
                unlink($wavPath);
                $this->encode($wav, $sentence);
            });
        $sentence = new Sentence($oggPath, $voiced);
        $voiced->catch($sentence->fail(...));

        return $sentence;
    }

    /**
     * Starts an ffmpeg that waits for the speech of one sentence.
     */
    private function encoder(): Encoder
    {
        // It reads a WAV file from a pipe, without first probing what it is. Frames of 20 ms, libopus's default:
        // see synthesize(). VoiceCallTest counts the packets of an answer. A page for each packet, written as
        // soon as it is encoded: ffmpeg would otherwise hold a second of them back.
        $encoder = new Encoder([
            $this->ffmpeg, '-loglevel', 'error', '-probesize', '32', '-analyzeduration', '0', '-f', 'wav', '-i', 'pipe:0',
            '-af', self::TRIM, '-c:a', 'libopus', '-page_duration', '20000', '-flush_packets', '1', '-f', 'ogg', 'pipe:1',
        ]);
        $id = spl_object_id($encoder);
        $this->encoders[$id] = $encoder;
        $ended = function () use ($id): void {
            unset($this->encoders[$id]);
        };
        $encoder->done()->then($ended, $ended);

        return $encoder;
    }

    /**
     * Has an ffmpeg wait for the speech of the next sentence, unless one does, or no sentence is coming: the
     * call is ending.
     */
    private function prepare(): void
    {
        if (! $this->stopping && ! $this->isReadyToEncode()) {
            $this->waiting = $this->encoder();
        }
    }

    /**
     * Gives a sentence's speech to the ffmpeg that waited for it, and has the next one started.
     */
    private function encode(string $wav, Sentence $sentence): void
    {
        // None waits when it stopped by itself, or when the call is ending and Piper had still been speaking
        // this sentence: one is started for it then.
        $encoder = $this->isReadyToEncode() ? $this->waiting : $this->encoder();
        $this->waiting = null;
        $this->prepare();
        $encoder->encode($wav, $sentence, $this->timeout);
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
