<?php

declare(strict_types=1);

namespace App\Voice;

use RuntimeException;

/**
 * Reads the Opus packets out of an Ogg Opus stream, such as the one ffmpeg writes with -c:a libopus: what is
 * sent to Discord, one packet for every 20 ms of speech. The stream may come in pieces, as from an ffmpeg that
 * is still writing it: {@see push()} gives the packets that each piece completes.
 *
 * An Ogg stream is a row of pages. Each page starts with "OggS", a 27-byte header and a table of segment sizes,
 * and the segments follow. A packet is the segments up to the first one shorter than 255 bytes, and may go
 * on from one page to the next. The first two packets of an Opus stream are its headers, OpusHead and OpusTags,
 * which Discord doesn't get.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3533 Ogg
 * @see https://www.rfc-editor.org/rfc/rfc7845 Opus in Ogg
 */
final class OggOpus
{
    /** The bytes of a page before its table of segment sizes. */
    private const int HEADER = 27;

    private const string NOT_OPUS = 'Not an Ogg Opus file: it does not start with the OpusHead and OpusTags headers.';

    /** What came of the stream and is not read yet: the start of a page's header, or of a segment. */
    private string $bytes = '';

    /** @var list<int> The sizes of the segments still to come of the page being read. */
    private array $segments = [];

    /** The segments so far of the packet being read. */
    private string $packet = '';

    /** How many packets were read: the first two are the headers. */
    private int $read = 0;

    /** Whether something that is no page came where a page had to start: nothing after it is read. */
    private bool $over = false;

    /**
     * @return list<string> The audio packets of a whole file, in order. A file cut off in the middle gives the
     *                      packets that are whole, and nothing of the one that isn't.
     * @throws RuntimeException When the bytes aren't the start of an Ogg Opus stream.
     */
    public static function packets(string $bytes): array
    {
        $stream = new self();
        $packets = $stream->push($bytes);
        $stream->end();

        return $packets;
    }

    /**
     * Takes the next piece of the stream.
     *
     * @return list<string> The audio packets that are whole with it, in order.
     * @throws RuntimeException When what came so far can't be the start of an Ogg Opus stream.
     */
    public function push(string $bytes): array
    {
        $bytes = $this->bytes . $bytes;
        $offset = 0;
        $length = strlen($bytes);
        $packets = [];

        while (! $this->over) {
            if ($this->segments === []) {
                // Before the whole header is there: what can't become a page is known from its first bytes.
                $start = substr($bytes, $offset, 4);

                if ($start !== substr('OggS', 0, strlen($start))) {
                    $this->over = true;

                    break;
                }

                if ($length - $offset < self::HEADER) {
                    break;
                }

                $segments = ord($bytes[$offset + 26]);

                if ($length - $offset < self::HEADER + $segments) {
                    break;
                }

                $this->segments = array_values(unpack('C*', substr($bytes, $offset + self::HEADER, $segments)) ?: []);
                $offset += self::HEADER + $segments;

                continue;
            }

            // Cut off in the middle of a segment: the rest of the packet has not come, or never will.
            if ($length - $offset < $this->segments[0]) {
                break;
            }

            $size = array_shift($this->segments);
            $this->packet .= substr($bytes, $offset, $size);
            $offset += $size;

            if ($size < 255) {
                $packet = $this->packet;
                $this->packet = '';

                if (++$this->read > 2) {
                    $packets[] = $packet;
                } elseif (! str_starts_with($packet, $this->read === 1 ? 'OpusHead' : 'OpusTags')) {
                    throw new RuntimeException(self::NOT_OPUS);
                }
            }
        }

        $this->bytes = substr($bytes, $offset);

        if ($this->over && ! $this->isOpus()) {
            throw new RuntimeException(self::NOT_OPUS);
        }

        return $packets;
    }

    /**
     * The stream is over: nothing more of it comes.
     *
     * @throws RuntimeException When what came wasn't the start of an Ogg Opus stream.
     */
    public function end(): void
    {
        if (! $this->isOpus()) {
            throw new RuntimeException(self::NOT_OPUS);
        }
    }

    /**
     * Whether both headers of an Opus stream were read: what comes after them is its audio.
     */
    private function isOpus(): bool
    {
        return $this->read >= 2;
    }
}
