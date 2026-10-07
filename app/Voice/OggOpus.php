<?php

declare(strict_types=1);

namespace App\Voice;

use RuntimeException;

/**
 * Reads the Opus packets out of an Ogg Opus file, such as the ones ffmpeg writes with -c:a libopus: what is
 * sent to Discord, one packet for every 20 ms of speech.
 *
 * An Ogg file is a row of pages. Each page starts with "OggS", a 27-byte header and a table of segment sizes,
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

    /**
     * @return list<string> The audio packets, in order. A file cut off in the middle gives the packets that are
     *                      whole, and nothing of the one that isn't.
     * @throws RuntimeException When the bytes aren't the start of an Ogg Opus stream.
     */
    public static function packets(string $bytes): array
    {
        $packets = [];
        $packet = '';
        $offset = 0;
        $length = strlen($bytes);

        while ($offset + self::HEADER <= $length && substr($bytes, $offset, 4) === 'OggS') {
            $segments = ord($bytes[$offset + 26]);
            $sizes = array_values(unpack('C*', substr($bytes, $offset + self::HEADER, $segments)) ?: []);
            $offset += self::HEADER + $segments;

            foreach ($sizes as $size) {
                // Cut off in the middle of a segment: the rest of the packet never came.
                if ($offset + $size > $length) {
                    return self::audio($packets);
                }

                $packet .= substr($bytes, $offset, $size);
                $offset += $size;

                if ($size < 255) {
                    $packets[] = $packet;
                    $packet = '';
                }
            }
        }

        return self::audio($packets);
    }

    /**
     * The packets after the two headers, once those are checked.
     *
     * @param list<string> $packets
     * @return list<string>
     */
    private static function audio(array $packets): array
    {
        if (! str_starts_with($packets[0] ?? '', 'OpusHead') || ! str_starts_with($packets[1] ?? '', 'OpusTags')) {
            throw new RuntimeException('Not an Ogg Opus file: it does not start with the OpusHead and OpusTags headers.');
        }

        return array_slice($packets, 2);
    }
}
