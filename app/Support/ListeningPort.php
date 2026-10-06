<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which port on 127.0.0.1 a process listens on, as Linux says in /proc.
 *
 * A program that is started on port 0 chooses its own port, and nobody else can have it: asking the system which
 * port it got, and whose it is, is not like choosing a free one and hoping that it is still free when the program binds it.
 */
final class ListeningPort
{
    /**
     * @return int|null The port, or null while the process listens on none of 127.0.0.1, and when there is no such process.
     */
    public static function of(int $pid): ?int
    {
        $sockets = [];

        foreach (@scandir("/proc/{$pid}/fd") ?: [] as $descriptor) {
            if (preg_match('/^socket:\[(\d+)\]$/', (string) @readlink("/proc/{$pid}/fd/{$descriptor}"), $socket) === 1) {
                $sockets[$socket[1]] = true;
            }
        }

        foreach (@file('/proc/net/tcp', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            // "sl local_address rem_address st tx_queue:rx_queue tr:tm->when retrnsmt uid timeout inode ...": 0A is
            // listening, and 127.0.0.1 is 0100007F, its bytes the other way around.
            $columns = preg_split('/\s+/', trim($line));

            if (($columns[3] ?? '') === '0A' && str_starts_with($columns[1] ?? '', '0100007F:') && isset($sockets[$columns[9] ?? ''])) {
                return (int) hexdec(substr($columns[1], strlen('0100007F:')));
            }
        }

        return null;
    }
}
