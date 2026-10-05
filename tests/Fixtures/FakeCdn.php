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
use React\Socket\ConnectionInterface;
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

    /** @var list<ConnectionInterface> Both ends of every connection, which are closed with it: sockets left open stay in the event loop. */
    private array $connections = [];

    public function __construct()
    {
        $this->response = new Response(200, ['Content-Type' => 'audio/ogg'], 'OggS fake voice message');
        $server = new HttpServer(function (ServerRequestInterface $request): ResponseInterface {
            $this->requests[] = ['host' => $request->getHeaderLine('Host'), 'target' => $request->getRequestTarget()];

            return $this->response instanceof Closure ? ($this->response)($request) : $this->response;
        });
        $this->socket = new SocketServer('127.0.0.1:0');
        $this->socket->on('connection', function (ConnectionInterface $connection) {
            $this->connections[] = $connection;
        });
        $server->listen($this->socket);
    }

    /**
     * From now on, until {@see close()}, downloads go to this server.
     */
    public function install(): void
    {
        $address = $this->socket->getAddress();

        $track = function (ConnectionInterface $connection): ConnectionInterface {
            return $this->connections[] = $connection;
        };

        (new ReflectionProperty(Download::class, 'connector'))->setValue(null, new class ($address, $track) implements ConnectorInterface {
            public function __construct(private readonly string $address, private readonly Closure $track)
            {
            }

            public function connect($uri): PromiseInterface
            {
                // Plain HTTP, though the request is for https: there is no certificate for Discord's hosts here.
                return (new TcpConnector())->connect($this->address)->then($this->track);
            }
        });
    }

    public function close(): void
    {
        (new ReflectionProperty(Download::class, 'connector'))->setValue(null, null);

        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->socket->close();
    }
}
