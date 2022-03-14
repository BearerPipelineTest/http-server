<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\RequestHandler;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\SocketServer;
use Psr\Log\LoggerInterface as PsrLogger;

interface ClientHandler
{
    /**
     * Handle a client received on the HTTP server.
     */
    public function handleClient(
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        Options $options,
        PsrLogger $logger,
        EncryptableSocket $socket,
    ): void;

    /**
     * Modify or wrap the given instance of {@see SocketServer} as necessary for this client handler.
     */
    public function setUpSocketServer(SocketServer $server): SocketServer;
}
