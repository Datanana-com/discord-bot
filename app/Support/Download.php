<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Socket\ConnectorInterface;
use RuntimeException;

use function React\Promise\reject;

/**
 * Downloads files from Discord's attachment hosts without blocking the event loop.
 */
final class Download
{
    /** The only hosts a file is downloaded from. */
    private const array HOSTS = ['cdn.discordapp.com', 'media.discordapp.net'];

    /** A file bigger than this is refused, not kept in memory. */
    private const int MAX_BYTES = 10 * 1024 * 1024;

    /** Seconds before a download is given up. */
    private const float TIMEOUT = 30.0;

    /** How connections are made, or null for the usual way. Only tests set it, to stand in for Discord. */
    private static ?ConnectorInterface $connector = null;

    /**
     * Saves the file at the URL.
     *
     * @return PromiseInterface<null> Rejects when the URL isn't https on one of Discord's attachment hosts,
     *                                or when the download fails. Redirects aren't followed, as they could
     *                                lead anywhere.
     */
    public static function toFile(string $url, string $path): PromiseInterface
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? '') !== 'https' || ! in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true)) {
            // The URL itself isn't in the message: Discord's carry a signature.
            return reject(new RuntimeException('Not downloading from ' . ($parts['host'] ?? 'an unknown host') . ": it isn't one of Discord's attachment hosts, or it isn't https."));
        }

        return (new Browser(self::$connector))
            ->withFollowRedirects(false)
            ->withTimeout(self::TIMEOUT)
            ->withResponseBuffer(self::MAX_BYTES)
            ->get($url)
            ->then(function (ResponseInterface $response) use ($path) {
                if ($response->getStatusCode() !== 200) {
                    throw new RuntimeException("Discord answered the download with HTTP status {$response->getStatusCode()}.");
                }

                if (@file_put_contents($path, (string) $response->getBody()) === false) {
                    throw new RuntimeException('The download could not be saved.');
                }
            });
    }
}
