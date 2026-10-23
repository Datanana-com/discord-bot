<?php

declare(strict_types=1);

namespace App\Voice;

use App\Support\CommandFailedException;
use App\Support\Program;
use App\Support\Shell;
use Closure;
use Discord\Voice\Processes\ProcessAbstract;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
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

    /** The voice process (Kokoro, or Piper once Kokoro was given up), while it runs. */
    private ?Program $piper = null;

    /** Where Piper writes the WAV file of each sentence. */
    private string $folder = '';

    /**
     * @var list<array{deferred: Deferred<string>, line: string, timer: TimerInterface}> The sentences the voice has not
     *      spoken yet, in the order it got them. Each resolves with its WAV file. The line is kept to give it to the
     *      fallback, when the voice that had it is given up.
     */
    private array $sentences = [];

    /** Whether Kokoro said READY: its model is loaded and warm. */
    private bool $ready = false;

    /** Why Kokoro was given up for Piper, once it was. */
    private ?string $fellBack = null;

    /** @var (Closure(string): void)|null Told why Kokoro was given up for Piper. */
    private ?Closure $onFallBack = null;

    /** Counts the processes started, so that what a process given up says or does later is not taken for the current one's. */
    private int $generation = 0;

    /** Fires when Kokoro has not said READY in time. */
    private ?TimerInterface $loading = null;

    /** The ffmpeg that waits for the speech of the next sentence: see {@see Encoder}. */
    private ?Encoder $waiting = null;

    /** @var array<int, Encoder> Every ffmpeg that has not ended: the one that waits, and those that encode a sentence. */
    private array $encoders = [];

    /** Whether stop() was called: no ffmpeg is started ahead for a sentence that is not coming. */
    private bool $stopping = false;

    /**
     * @param float $timeout Seconds Piper has to speak a sentence, and ffmpeg to encode it, before it is stopped.
     * @param bool $kokoro Whether binary is Kokoro, which loads for seconds and says when it is ready.
     * @param string|null $fallbackBinary Piper, when binary is Kokoro: the voice a call goes on with, from the sentence
     *        Kokoro has not spoken, when Kokoro ends, runs on the processor, is not ready in $loadSeconds or takes more
     *        than $slowSeconds over a sentence. Null for a voice that has no fallback.
     */
    public function __construct(
        public readonly string $binary,
        public readonly string $model,
        public readonly string $ffmpeg = 'ffmpeg',
        private readonly float $timeout = 120.0,
        private readonly ?string $fallbackBinary = null,
        private readonly ?string $fallbackModel = null,
        private readonly float $loadSeconds = 30.0,
        private readonly float $slowSeconds = 5.0,
        private readonly bool $kokoro = false,
    ) {
    }

    /**
     * Kokoro speaks when it is installed and has the voice (a server's, or KOKORO_VOICE), with Piper as its fallback
     * when Piper is installed; any other voice is a Piper voice, and has no fallback.
     *
     * @param string|null $voice The name of the voice that speaks, when it is not the one in .env: one of voices().
     */
    public static function fromEnv(?string $voice = null): self
    {
        $model = env('PIPER_MODEL', '');
        $piper = env('PIPER_BINARY', 'piper');
        $ffmpeg = env('FFMPEG_BINARY', 'ffmpeg');
        $kokoro = self::kokoro();
        $name = $voice ?? env('KOKORO_VOICE', 'af_heart');

        if ($kokoro !== null && is_file("{$kokoro['folder']}/{$name}.onnx")) {
            $installed = ProcessAbstract::checkForExecutable($piper) !== null && is_file($model);

            return new self($kokoro['binary'], "{$kokoro['folder']}/{$name}.onnx", $ffmpeg, fallbackBinary: $installed ? $piper : null, fallbackModel: $installed ? $model : null, kokoro: true);
        }

        return new self($piper, $voice === null ? $model : dirname($model) . "/{$voice}.onnx", $ffmpeg);
    }

    /**
     * The names of the installed voices: Kokoro's, then those in the same folder as the Piper voice in .env.
     * The voice in en_US-lessac-medium.onnx is called "en_US-lessac-medium".
     *
     * @return list<string>
     */
    public static function voices(): array
    {
        $folders = [dirname(env('PIPER_MODEL', ''))];

        if (($kokoro = self::kokoro()) !== null) {
            array_unshift($folders, $kokoro['folder']);
        }

        $voices = [];

        foreach ($folders as $folder) {
            // Not glob(): a folder's name can have characters that mean something in a pattern.
            $files = is_dir($folder) ? scandir($folder) : [];

            foreach ($files as $file) {
                if (str_ends_with($file, '.onnx')) {
                    $voices[] = basename($file, '.onnx');
                }
            }
        }

        return array_values(array_unique($voices));
    }

    /**
     * Kokoro's program and the folder of its voices: KOKORO_BINARY, or this repository's own when `kokoro/install.sh`
     * has run. Null when it is not there, or KOKORO_BINARY is empty.
     *
     * @return array{binary: string, folder: string}|null
     */
    private static function kokoro(): ?array
    {
        $own = dirname(__DIR__, 2) . '/kokoro';
        $binary = env('KOKORO_BINARY') ?? (is_file("{$own}/venv/bin/python") ? "{$own}/kokoro" : '');

        return $binary === '' ? null : ['binary' => $binary, 'folder' => dirname($binary) . '/voices'];
    }

    /**
     * Whether the voice is Kokoro, which may still be given up for Piper.
     */
    private function mayFallBack(): bool
    {
        return $this->fallbackBinary !== null && $this->fellBack === null;
    }

    /**
     * Whether a sentence can be spoken without waiting for the voice to load: Kokoro said it is ready, or it was
     * given up, or the voice is Piper's, which is quick to start.
     */
    public function isReady(): bool
    {
        return $this->ready || ! $this->kokoro || $this->fellBack !== null;
    }

    /**
     * Has $callback told why Kokoro was given up for Piper, now if it already was.
     *
     * @param Closure(string): void $callback
     */
    public function whenFallingBack(Closure $callback): void
    {
        $this->onFallBack = $callback;

        if ($this->fellBack !== null) {
            $callback($this->fellBack);
        }
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
        $this->launch();
    }

    /**
     * Starts the program that speaks: Kokoro, or Piper when Kokoro was given up.
     */
    private function launch(): void
    {
        $binary = $this->fellBack === null ? $this->binary : (string) $this->fallbackBinary;
        $model = $this->fellBack === null ? $this->model : (string) $this->fallbackModel;
        // What an earlier program says from now on is not taken for this one's.
        $generation = ++$this->generation;
        // With --output-dir, Piper keeps reading its stdin: it speaks each line into a WAV file of the folder,
        // and says on stderr when the file is complete.
        $this->piper = Shell::open([$binary, '--model', $model, '--output-dir', $this->folder], onErrorLine: fn (string $line) => $this->wrote($line, $generation));
        $this->piper->done()
            ->then(fn () => new CommandFailedException("{$binary} ended before it spoke the sentence", ''), fn (Throwable $e) => $e)
            ->then(fn (Throwable $how) => $this->ended($how, $generation));

        if ($this->mayFallBack()) {
            // Kokoro takes 7 to 17 s to load: a call waits that long for it, and no longer.
            $this->loading = Loop::addTimer($this->loadSeconds, fn () => $this->fallBack('it was not ready in time'));
        }
    }

    /**
     * Gives Kokoro up for Piper: for the rest of the call, from the first sentence Kokoro has not spoken. The ones it
     * had, in the order it got them, go to Piper.
     */
    private function fallBack(string $reason): void
    {
        if (! $this->mayFallBack() || $this->stopping) {
            return;
        }

        $this->fellBack = $reason;
        $this->cancelLoading();
        $old = $this->piper;
        $this->piper = null;
        $old?->stop($reason);
        $this->emptyFolder();
        $this->launch();

        foreach ($this->sentences as $i => $sentence) {
            Loop::cancelTimer($sentence['timer']);
            $this->sentences[$i]['timer'] = $this->timeOut();
            $this->piper?->write("{$sentence['line']}\n");
        }

        $this->onFallBack?->__invoke($reason);
    }

    private function cancelLoading(): void
    {
        if ($this->loading !== null) {
            Loop::cancelTimer($this->loading);
            $this->loading = null;
        }
    }

    /**
     * Starts the clock of a sentence just given to the voice: it has as long as the voice is allowed, which Kokoro
     * has little of once it is ready, and the load to wait for before.
     */
    private function timeOut(): TimerInterface
    {
        $seconds = match (true) {
            ! $this->mayFallBack() => $this->timeout,
            $this->ready => $this->slowSeconds,
            default => $this->loadSeconds,
        };

        return Loop::addTimer($seconds, function (): void {
            if ($this->mayFallBack()) {
                $this->fallBack('a sentence took too long');
            } else {
                $this->piper?->stop("timed out after {$this->timeout}s");
            }
        });
    }

    private function emptyFolder(): void
    {
        foreach (is_dir($this->folder) ? scandir($this->folder) : [] as $file) {
            is_file("{$this->folder}/{$file}") && unlink("{$this->folder}/{$file}");
        }
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
        $this->cancelLoading();
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

        // Before the voice is started: when it can't be, it has ended at once, and this sentence with it.
        $this->sentences[] = ['deferred' => $spoken = new Deferred(), 'line' => $line, 'timer' => $this->timeOut()];
        $this->start($this->folder);

        $this->piper?->write("{$line}\n");

        $sentence = null;
        $voiced = $spoken->promise()
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
     * Reads a line the voice printed on stderr: "INFO:__main__:Wrote <path>" once the WAV file of a sentence is
     * complete. Kokoro also says where it runs ("DEVICE cpu" or "DEVICE cuda (<card>)"), when it is ready ("READY
     * {...}") and how long each sentence took ("FIRST ..."): none of that says why it fails.
     *
     * @return bool Whether it was that, and not something that may say why the voice fails.
     */
    private function wrote(string $line, int $generation): bool
    {
        // A voice that was given up may still say something: it is not the one that speaks.
        if ($generation !== $this->generation) {
            return true;
        }

        if (preg_match('/^INFO:__main__:Wrote (.+)$/', $line, $match) === 1) {
            $sentence = array_shift($this->sentences);

            if ($sentence !== null) {
                Loop::cancelTimer($sentence['timer']);
                $sentence['deferred']->resolve($match[1]);
            }

            return true;
        }

        if (! $this->kokoro || preg_match('/^(DEVICE|READY|FIRST)\b/', $line, $match) !== 1) {
            return false;
        }

        // Without a card Kokoro takes 2 to 6 s over a sentence, and says nothing about it but this.
        if ($line === 'DEVICE cpu') {
            $this->fallBack('it runs on the processor');
        } elseif ($match[1] === 'READY') {
            $this->ready = true;
            $this->cancelLoading();
        }

        return true;
    }

    /**
     * The voice has ended: Kokoro is given up for Piper, and the sentences Piper had not spoken never will be.
     */
    private function ended(Throwable $how, int $generation): void
    {
        if ($generation !== $this->generation) {
            return;
        }

        if ($this->mayFallBack() && ! $this->stopping) {
            $this->fallBack('it ended');

            return;
        }

        $this->piper = null;

        // The file of a sentence it failed on, which it never said it wrote.
        $this->emptyFolder();
        @rmdir($this->folder);

        foreach (array_splice($this->sentences, 0) as $sentence) {
            Loop::cancelTimer($sentence['timer']);
            $sentence['deferred']->reject($how);
        }
    }
}
