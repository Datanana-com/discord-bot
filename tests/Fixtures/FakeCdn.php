<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Download;
use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\ConnectorInterface;
use React\Socket\SocketServer;
use React\Socket\TcpConnector;
use ReflectionProperty;

/**
 * Stands in for Discord's attachment hosts: a web server on this machine that every download is
 * sent to, whatever host its URL names. {@see install()} makes {@see Download} use it.
 */
final class FakeCdn
{
    /** @var list<array{host: string, target: string}> The requests it got: the Host header and the path with its query. */
    public array $requests = [];

    /** What it answers with. */
    public ResponseInterface|Closure $response;

    private SocketServer $socket;

    public function __construct()
    {
        $this->response = new Response(200, ['Content-Type' => 'audio/ogg'], 'OggS fake voice message');
        $server = new HttpServer(function (ServerRequestInterface $request): ResponseInterface {
            $this->requests[] = ['host' => $request->getHeaderLine('Host'), 'target' => $request->getRequestTarget()];

            return $this->response instanceof Closure ? ($this->response)($request) : $this->response;
        });
        $this->socket = new SocketServer('127.0.0.1:0');
        $server->listen($this->socket);
    }

    /**
     * From now on, until {@see close()}, downloads go to this server.
     */
    public function install(): void
    {
        $address = $this->socket->getAddress();

        (new ReflectionProperty(Download::class, 'connector'))->setValue(null, new class ($address) implements ConnectorInterface {
            public function __construct(private readonly string $address)
            {
            }

            public function connect($uri): PromiseInterface
            {
                // Plain HTTP, though the request is for https: there is no certificate for Discord's hosts here.
                return (new TcpConnector())->connect($this->address);
            }
        });
    }

    public function close(): void
    {
        (new ReflectionProperty(Download::class, 'connector'))->setValue(null, null);
        $this->socket->close();
    }
}
