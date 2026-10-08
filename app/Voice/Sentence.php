<?php

declare(strict_types=1);

namespace App\Voice;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

/**
 * A sentence on its way from the voice to the call: {@see Speech::synthesize()} gives one for every sentence
 * it takes.
 *
 * Three things happen to it, one after the other. The voice speaks it, after which the voice is free for the
 * next sentence. Then its speech is encoded, and the first of its Ogg Opus stream arrives: from then on it can
 * be played, while the rest still comes. And then it is whole, and its file is written. Whoever hands sentences
 * to the voice waits for the first, {@see OggPlayer} plays from the second, and {@see LibraryPlayer}, which
 * plays files, from the third.
 */
final class Sentence
{
    /** Its whole Ogg Opus stream, as far as it came. */
    private string $bytes = '';

    /** What came of the stream since its packets were last asked for. */
    private string $unread = '';

    /** Reads the packets out of the stream, once someone asks for them. */
    private readonly OggOpus $stream;

    /** @var list<string> The Opus packets read so far. */
    private array $packets = [];

    /** @var list<callable(): void> Who is told when more of it came, and when it is over. */
    private array $watchers = [];

    /** @var Deferred<null> */
    private readonly Deferred $started;

    /** @var Deferred<null> */
    private readonly Deferred $whole;

    /** Whether the stream is over: it is whole, or it failed. */
    private bool $over = false;

    /** Why it failed, when it did. */
    private ?Throwable $failure = null;

    /** Whether nobody wants to hear it any more. */
    private bool $dropped = false;

    /**
     * @param string $path Where its Ogg Opus file is written, once it is whole.
     * @param PromiseInterface<null> $voiced Resolves once the voice has spoken it.
     */
    public function __construct(
        public readonly string $path,
        private readonly PromiseInterface $voiced,
    ) {
        $this->stream = new OggOpus();
        $this->started = new Deferred();
        $this->whole = new Deferred();

        // Nobody may be waiting for one of the three, and each can fail.
        foreach ([$voiced, $this->started->promise(), $this->whole->promise()] as $step) {
            $step->catch(static fn () => null);
        }
    }

    /**
     * @return PromiseInterface<null> Resolves once the voice has spoken it, and is free for the next sentence.
     *                                Rejects when the voice didn't: it ended, or took too long.
     */
    public function voiced(): PromiseInterface
    {
        return $this->voiced;
    }

    /**
     * @return PromiseInterface<null> Resolves once the first of its stream is there. Rejects when the voice
     *                                didn't speak it, or its speech couldn't be encoded.
     */
    public function started(): PromiseInterface
    {
        return $this->started->promise();
    }

    /**
     * @return PromiseInterface<null> Resolves once its stream is complete, and its file written. Rejects when
     *                                the voice didn't speak it, or its speech couldn't be encoded.
     */
    public function whole(): PromiseInterface
    {
        return $this->whole->promise();
    }

    /**
     * @return list<string> Its Opus packets, as far as they came: 20 ms of speech each, in order.
     * @throws RuntimeException When its stream isn't Ogg Opus.
     */
    public function packets(): array
    {
        if ($this->unread !== '') {
            $unread = $this->unread;
            $this->unread = '';
            array_push($this->packets, ...$this->stream->push($unread));
        }

        if ($this->over && $this->failure === null) {
            $this->stream->end();
        }

        return $this->packets;
    }

    /**
     * Whether nothing more of it comes: it is whole, or it failed.
     */
    public function isOver(): bool
    {
        return $this->over;
    }

    /**
     * Why it failed, when it did: its packets so far are all there will be.
     */
    public function failure(): ?Throwable
    {
        return $this->failure;
    }

    /**
     * Has someone told whenever more of its stream came, and when it is over.
     *
     * @param callable(): void $watcher
     */
    public function watch(callable $watcher): void
    {
        $this->watchers[] = $watcher;
    }

    /**
     * The next piece of its stream has come from the encoder.
     */
    public function write(string $bytes): void
    {
        if ($this->over || $bytes === '') {
            return;
        }

        $this->bytes .= $bytes;
        $this->unread .= $bytes;
        $this->started->resolve(null);
        $this->tell();
    }

    /**
     * Its stream is complete: the file is written, unless nobody wants to hear it any more.
     */
    public function end(): void
    {
        if ($this->over) {
            return;
        }

        $this->over = true;

        if (! $this->dropped) {
            file_put_contents($this->path, $this->bytes);
        }

        // A stream of nothing, which no encoder gives, is over all the same.
        $this->started->resolve(null);
        $this->whole->resolve(null);
        $this->tell();
    }

    /**
     * The voice didn't speak it, or the encoder failed: what came of it is all there will be.
     */
    public function fail(Throwable $why): void
    {
        if ($this->over) {
            return;
        }

        $this->over = true;
        $this->failure = $why;
        $this->started->reject($why);
        $this->whole->reject($why);
        $this->tell();
    }

    /**
     * Nobody wants to hear it any more: its file is not written, or deleted when it already was. The voice still
     * speaks a sentence it has started, which can't be taken back.
     */
    public function drop(): void
    {
        $this->dropped = true;

        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    private function tell(): void
    {
        foreach ($this->watchers as $watcher) {
            $watcher();
        }
    }
}
