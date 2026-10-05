<?php

declare(strict_types=1);

namespace Tests;

/**
 * Lets a test see what the bot does when it can't start a program.
 */
trait RunsOutOfFileDescriptors
{
    /**
     * Runs the code while PHP can open nothing more, as when too many files, sockets and programs are open
     * already: starting a program takes a file descriptor for each of its pipes.
     *
     * Only the classes that are loaded already can be used in it: loading one opens its file.
     */
    protected function withoutFileDescriptors(callable $do): mixed
    {
        [$soft, $hard] = array_map(
            fn (int|string $limit) => $limit === 'unlimited' ? POSIX_RLIMIT_INFINITY : $limit,
            [posix_getrlimit()['soft openfiles'], posix_getrlimit()['hard openfiles']],
        );
        // Standard input, output and error are open, and nothing else fits.
        posix_setrlimit(POSIX_RLIMIT_NOFILE, 3, $hard);

        try {
            return $do();
        } finally {
            posix_setrlimit(POSIX_RLIMIT_NOFILE, $soft, $hard);
        }
    }
}
