<?php

declare(strict_types=1);

namespace App\Voice;

use App\Support\ListeningPort;
use App\Support\Program;
use App\Support\Shell;
use Closure;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * whisper.cpp's whisper-server, kept running for as long as there is a call, so that what someone says is
 * transcribed by a process that has its model loaded already. whisper-cli loads the model and starts the GPU
 * for every utterance, which takes about as long as transcribing it: on a short question, 0.5 to 0.9 s against
 * 0.1 to 0.3 s with a server that is warm, on a CUDA build. A build without a GPU loads its model faster, and gains less.
 *
 * A call takes the server with acquire() and gives it back with release(). The server is the same for every
 * call, and ends with the last one. It is not an answer to everything: while it loads its model, after it failed, and for
 * recordings too long to make the calls wait for them, whisper-cli transcribes, see {@see Transcriber}.
 *
 * The server has no password, so it takes care not to be found: it chooses its own port, on 127.0.0.1, which the bot
 * learns from the system, and answers only under a path that is chosen at random each time it starts, which a web page can't guess.
 * A program of any user on the same machine can read that path from the list of processes, and use the server: on a
 * machine shared with people who are not trusted, leave it to whisper-cli.
 *
 * @see https://github.com/ggml-org/whisper.cpp/tree/master/examples/server
 */
final class WhisperServer
{
    /** How long a request may take, for each second of audio, besides the minimum: the server does a second of speech in about 20 ms. */
    public const float SECONDS_PER_SECOND_OF_AUDIO = 0.2;

    /** Seconds between looking for the port of a server that is loading its model. */
    private const float POLL_SECONDS = 0.1;

    /** @var array<string, self> The servers there are, by what they are started with: see {@see fromEnv()}. */
    private static array $shared = [];

    /** The server's process, from when it is started to when it ends or is given up. */
    private ?Program $program = null;

    private int $port = 0;

    /** What every path of the server starts with: random, and different for each server that is started. */
    private string $path = '';

    /** Whether the server has loaded its model and answered a first request: only then does it get utterances. */
    private bool $ready = false;

    /** How many calls hold it: it runs while there is one. */
    private int $users = 0;

    /** Looks for the port of a server that is loading. */
    private ?TimerInterface $poll = null;

    /** Starts it again, after it ended by itself. */
    private ?TimerInterface $restart = null;

    /** @var (Closure(string $level, string $message, array<string, mixed> $context): void)|null */
    private ?Closure $log = null;

    private readonly Browser $browser;

    /**
     * @param int|null $threads How many threads the server uses, or null for as many as it takes by itself.
     * @param float $startupSeconds How long the server has to load its model and listen, before it is given up.
     * @param float $restartAfter Seconds before a server that ended by itself is started again.
     * @param float $minimumTimeout Seconds a request has at least, whatever the length of the audio.
     */
    public function __construct(
        public readonly string $binary,
        public readonly string $model,
        public readonly ?int $threads = null,
        private readonly float $startupSeconds = 60.0,
        private readonly float $restartAfter = 5.0,
        private readonly float $minimumTimeout = 3.0,
    ) {
        $this->browser = new Browser();
    }

    /**
     * The server for the model in .env, which every call shares, or null when there is none to run.
     *
     * It is the whisper-server in WHISPER_SERVER_BINARY, or when that isn't set, the one next to whisper-cli,
     * where whisper.cpp builds it, if it is there. Empty, WHISPER_SERVER_BINARY leaves it to whisper-cli.
     *
     * @param int|null $threads WHISPER_THREADS, as {@see Transcriber::fromEnv()} read it.
     */
    public static function fromEnv(string $model, ?int $threads): ?self
    {
        $cli = env('WHISPER_BINARY', 'whisper-cli');
        $beside = (str_contains($cli, '/') ? dirname($cli) . '/' : '') . 'whisper-server';
        $set = env('WHISPER_SERVER_BINARY');
        // env() makes "false", "true" and "null" in .env what they say. False is as empty as empty is, the others are not a path.
        $binary = is_string($set) ? $set : ($set === false || ! is_executable($beside) ? '' : $beside);

        if ($binary === '') {
            return null;
        }

        return self::$shared["{$binary}|{$model}|{$threads}"] ??= new self($binary, $model, $threads);
    }

    /**
     * Takes the server for a call, and starts it unless it runs already. It never fails: a server that can't be
     * started is one whisper-cli does without.
     *
     * @param Closure(string $level, string $message, array<string, mixed> $context): void $log Where it says what
     *                                                                                           happens to it: the
     *                                                                                           log of the latest call
     *                                                                                           that took it.
     */
    public function acquire(Closure $log): void
    {
        $this->log = $log;
        $this->users++;

        if ($this->program === null) {
            $this->start();
        }
    }

    /**
     * Gives the server back. The last call to give it back ends it.
     *
     * @return PromiseInterface<mixed> Resolves once the server is gone, or at once when another call still has it.
     *                                 It never rejects.
     */
    public function release(): PromiseInterface
    {
        if ($this->users === 0 || --$this->users > 0) {
            return resolve(null);
        }

        $program = $this->program;
        $this->detach();
        $program?->stop();
        // No call is left to tell, and the log of the last one would keep it from being forgotten.
        $this->log = null;

        return $program === null ? resolve(null) : $program->done()->catch(static fn () => null);
    }

    /**
     * Whether it can transcribe now.
     */
    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * @return PromiseInterface<string> What whisper heard, as it wrote it, annotations included. Rejects when the
     *                                  server isn't ready, can't be asked, or doesn't answer in time: in that
     *                                  case it is also given up, and started again. An answer that says no
     *                                  is no failure of the server's, and doesn't stop it.
     */
    public function transcribe(string $wavPath, string $language, string $prompt, float $seconds): PromiseInterface
    {
        $program = $this->program;

        if (! $this->ready || $program === null) {
            return reject(new RuntimeException('The whisper server is not ready.'));
        }

        $audio = @file_get_contents($wavPath);

        if ($audio === false) {
            return reject(new RuntimeException("Could not read {$wavPath}."));
        }

        return $this->post(
            ['language' => $language, 'response_format' => 'json', ...($prompt === '' ? [] : ['prompt' => $prompt])],
            $audio,
            $this->minimumTimeout + self::SECONDS_PER_SECOND_OF_AUDIO * $seconds,
        )->then(function ($response) {
            $text = json_decode((string) $response->getBody(), true)['text'] ?? null;

            // Without quoting what it sent: it can hold what someone said, and the message is logged.
            return is_string($text) ? $text : throw new RuntimeException('The whisper server did not say what it heard.');
        })->catch(function (Throwable $e) use ($program) {
            // Not answering is the server's fault. An answer that says no (a file it can't read) is the file's.
            if (! $e instanceof ResponseException) {
                $this->fault($program, $e->getMessage());
            }

            throw $e;
        });
    }

    /**
     * Starts the server: it chooses a port, once its model is loaded, and answers under a path of its own.
     */
    private function start(): void
    {
        $this->cancelRestart();
        $this->ready = false;
        $this->port = 0;
        $this->path = '/' . bin2hex(random_bytes(16));
        $startedAt = microtime(true);
        // With the bot gone, the server goes: setpriv has Linux end it with the process that started it.
        $this->program = $program = Shell::open([
            'setpriv', '--pdeathsig', 'TERM',
            $this->binary,
            '--model', $this->model,
            '--host', '127.0.0.1',
            // Port 0 is whatever port is free when the server binds it, which nothing else can be quicker to take than the server itself.
            '--port', '0',
            '--request-path', $this->path,
            '--no-timestamps',
            // As whisper-cli decodes: its beam search, not the server's greedy default, which wrote other words in a fifth of the test utterances.
            '--beam-size', '5',
            '--best-of', '5',
            ...($this->threads === null ? [] : ['--threads', (string) $this->threads]),
        ]);
        $program->done()
            ->then(static fn () => 'it ended', static fn (Throwable $e) => $e->getMessage())
            ->then(fn (string $why) => $this->fault($program, $why));
        $this->waitUntilListening($program, $startedAt);
    }

    /**
     * Looks for the port the server chose, again and again, until it listens on one: that is once its model is loaded.
     * The port is the one the system says belongs to the server's process, so that nothing else that listens somewhere
     * can be taken for it.
     */
    private function waitUntilListening(Program $program, float $startedAt): void
    {
        $this->poll = null;
        $pid = $program->pid();

        // It ended, and its done() says why.
        if ($pid === null) {
            return;
        }

        if (microtime(true) - $startedAt > $this->startupSeconds) {
            $this->fault($program, "it did not start within {$this->startupSeconds}s");

            return;
        }

        $port = ListeningPort::of($pid);

        if ($port === null) {
            $this->poll = Loop::addTimer(self::POLL_SECONDS, fn () => $this->waitUntilListening($program, $startedAt));

            return;
        }

        $this->port = $port;
        $this->browser->withTimeout(2.0)->get($this->url('/health'))->then(
            fn () => $this->warmUp($program, $startedAt),
            fn (Throwable $e) => $this->fault($program, 'it did not say that it is healthy: ' . $e->getMessage()),
        );
    }

    /**
     * Gives the server a second of silence to transcribe: its first request takes longer than the ones after it, and
     * it must not be someone's question. The server is ready once it has answered.
     */
    private function warmUp(Program $program, float $startedAt): void
    {
        $this->post(['language' => 'en', 'response_format' => 'json'], self::silence(), 30.0)->then(
            function () use ($startedAt) {
                $this->ready = true;
                $this->say('info', 'Whisper server ready', ['ms' => (int) round((microtime(true) - $startedAt) * 1000), 'port' => $this->port]);
            },
            fn (Throwable $e) => $this->fault($program, 'its first request failed: ' . $e->getMessage()),
        );
    }

    /**
     * The server failed: it is given up, and whisper-cli transcribes until it is started again, which it is when it
     * had served before. A server that never got to serve is not tried again until a call takes it: whatever
     * stopped it would stop it again.
     */
    private function fault(Program $program, string $why): void
    {
        if ($this->program !== $program) {
            return;
        }

        $again = $this->ready;
        $this->detach();
        $program->stop($why);
        $this->say('warning', 'The whisper server stopped (' . rtrim($why, '.') . ')' . ($again ? ' and starts again.' : '.'));

        if ($again) {
            // A call that takes the server meanwhile starts it, and one that gives it back ends the wait.
            $this->restart = Loop::addTimer($this->restartAfter, function () {
                $this->restart = null;
                $this->start();
            });
        }
    }

    /**
     * Lets go of the server's process, and of what waits for it.
     */
    private function detach(): void
    {
        $this->program = null;
        $this->ready = false;
        $this->cancelRestart();

        if ($this->poll !== null) {
            Loop::cancelTimer($this->poll);
            $this->poll = null;
        }
    }

    private function cancelRestart(): void
    {
        if ($this->restart !== null) {
            Loop::cancelTimer($this->restart);
            $this->restart = null;
        }
    }

    /**
     * @param array<string, string> $fields
     * @return PromiseInterface<\Psr\Http\Message\ResponseInterface>
     */
    private function post(array $fields, string $audio, float $timeout): PromiseInterface
    {
        $boundary = bin2hex(random_bytes(16));
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }

        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"audio.wav\"\r\nContent-Type: audio/wav\r\n\r\n{$audio}\r\n--{$boundary}--\r\n";

        return $this->browser->withTimeout($timeout)->post($this->url('/inference'), ['Content-Type' => "multipart/form-data; boundary={$boundary}"], $body);
    }

    private function url(string $path): string
    {
        return "http://127.0.0.1:{$this->port}{$this->path}{$path}";
    }

    /**
     * A second of silence, as 16 kHz mono WAV.
     */
    private static function silence(): string
    {
        $samples = str_repeat("\0", 16000 * 2);

        return pack('a4Va4a4VvvVVvva4V', 'RIFF', 36 + strlen($samples), 'WAVE', 'fmt ', 16, 1, 1, 16000, 32000, 2, 16, 'data', strlen($samples)) . $samples;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function say(string $level, string $message, array $context = []): void
    {
        $this->log?->__invoke($level, $message, $context);
    }
}
