<?php

declare(strict_types=1);

namespace Tests;

/**
 * Builds Ogg Opus streams byte by byte, for the tests of what reads them.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3533 Ogg
 * @see https://www.rfc-editor.org/rfc/rfc7845 Opus in Ogg
 */
final class Ogg
{
    /** The page is the first of its stream. */
    public const int FIRST = 0x02;

    /** The page goes on with a packet the page before it didn't finish. */
    public const int CONTINUED = 0x01;

    /** The page is the last of its stream. */
    public const int LAST = 0x04;

    /**
     * An Ogg Opus stream of these audio packets, laid out like ffmpeg lays one out: the OpusHead and OpusTags
     * headers on a page each, then the packets, as many per page as asked.
     *
     * @param list<string> $packets
     */
    public static function opus(array $packets, int $perPage = PHP_INT_MAX): string
    {
        // Version 1, one channel, 312 samples of pre-skip, 48 kHz, no gain, channel mapping 0.
        $head = 'OpusHead' . "\x01\x01" . pack('v', 312) . pack('V', 48000) . pack('v', 0) . "\x00";
        // No vendor string, no comments.
        $tags = 'OpusTags' . pack('V', 0) . pack('V', 0);
        $stream = self::page([$head], 0, self::FIRST) . self::page([$tags], 1);
        $pages = array_chunk($packets, $perPage);

        foreach ($pages as $number => $page) {
            $stream .= self::page($page, 2 + $number, $number === count($pages) - 1 ? self::LAST : 0);
        }

        return $stream;
    }

    /**
     * A page holding these whole packets.
     *
     * @param list<string> $packets
     */
    public static function page(array $packets, int $sequence, int $type = 0): string
    {
        $lacing = '';

        foreach ($packets as $packet) {
            $lacing .= self::lacing(strlen($packet));
        }

        return self::rawPage($lacing, implode('', $packets), $sequence, $type);
    }

    /**
     * A page from its table of segment sizes and its data, for packets that are cut between two pages.
     */
    public static function rawPage(string $lacing, string $data, int $sequence, int $type = 0): string
    {
        // "OggS", version 0, the type, the granule position, the serial number, the sequence number, a CRC nobody checks here, and the table.
        return 'OggS' . "\x00" . chr($type) . pack('P', 0) . pack('V', 1) . pack('V', $sequence) . pack('V', 0) . chr(strlen($lacing)) . $lacing . $data;
    }

    /**
     * The segment sizes of a whole packet of that length: 255 for each full segment, then what is left, which may be 0.
     */
    public static function lacing(int $length): string
    {
        return str_repeat("\xff", intdiv($length, 255)) . chr($length % 255);
    }
}
