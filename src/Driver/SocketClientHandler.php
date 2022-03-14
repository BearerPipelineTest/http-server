<?php

namespace Amp\Http\Server\Driver;

use Amp\CancelledException;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\RequestHandler;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\SocketServer;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface as PsrLogger;

final class SocketClientHandler implements ClientHandler
{
    public const ALPN = ["h2", "http/1.1"];

    public function __construct(
        private readonly TimeoutQueue $timeoutQueue = new DefaultTimeoutQueue,
        private readonly float $tlsHandshakeTimeout = 5,
    ) {
    }

    public function handleClient(
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        Options $options,
        PsrLogger $logger,
        EncryptableSocket $socket
    ): void
    {
        $client = $this->createClient($socket);

        $httpDriver = $this->createHttpDriver($errorHandler, $options, $logger, $client);

        $httpDriver->handleClient(
            $requestHandler,
            $client,
            $socket,
            $socket,
        );
    }

    public function setUpSocketServer(SocketServer $server): SocketServer
    {
        $resource = $server->getResource();

        if ($resource && $server->getBindContext()?->getTlsContext()) {
            \stream_context_set_option($resource, 'ssl', 'alpn_protocols', \implode(', ', self::ALPN));
        }

        return $server;
    }

    private function createClient(EncryptableSocket $socket): ?Client
    {
        if (isset(\stream_context_get_options($socket->getResource())["ssl"])) {
            try {
                $socket->setupTls(new TimeoutCancellation($this->tlsHandshakeTimeout));
            } catch (CancelledException) {
                return null;
            }
        }

        return new SocketClient($socket);
    }

    private function createHttpDriver(
        ErrorHandler $errorHandler,
        Options $options,
        PsrLogger $logger,
        Client $client
    ): HttpDriver {
        if ($client->getTlsInfo()?->getApplicationLayerProtocol() === "h2") {
            return new Http2Driver($this->timeoutQueue, $errorHandler, $logger, $options);
        }

        return new Http1Driver($this->timeoutQueue, $errorHandler, $logger, $options);
    }

}
