<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\RequestHandler;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\SocketServer;
use Psr\Log\LoggerInterface as PsrLogger;

final class ConnectionLimitingClientHandler implements ClientHandler
{
    private int $clientCount = 0;

    /** @var array<string, Client> */
    private array $clientsPerIp = [];

    public function __construct(
        private readonly ClientHandler $delegate,
    ) {
    }

    public function handleClient(
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        Options $options,
        PsrLogger $logger,
        EncryptableSocket $socket,
    ): void {
        if (++$this->clientCount > $options->getConnectionLimit()) {
            $logger->warning("Client denied: too many existing connections");
            $socket->close();

            return;
        }

        $ip = $net = $socket->getRemoteAddress()->getHost();
        $packedIp = \inet_pton($ip);

        if ($packedIp !== false && isset($net[4])) {
            $net = \substr($net, 0, 7 /* /56 block for IPv6 */);
        }

        $this->clientsPerIp[$net] ??= 0;
        $clientsPerIp = ++$this->clientsPerIp[$net];

        // Connections via unix sockets or on localhost are excluded from the connections per IP setting.
        // Checks IPv4 loopback (127.x), IPv6 loopback (::1) and IPv4-to-IPv6 mapped loopback.
        $isLocalhost = $socket->getLocalAddress()->getPort() === null || $ip === "::1" || \str_starts_with($ip, "127.")
            || \str_starts_with(\inet_pton($ip), "\0\0\0\0\0\0\0\0\0\0\xff\xff\x7f");

        if (!$isLocalhost && $clientsPerIp >= $options->getConnectionsPerIpLimit()) {
            if (isset($packedIp[4])) {
                $ip .= "/56";
            }

            $logger->warning("Client denied: too many existing connections from {$ip}");
            $socket->close();

            return;
        }

        try {
            $this->delegate->handleClient($requestHandler, $errorHandler, $options, $logger, $socket);
        } finally {
            if (--$this->clientsPerIp[$net] === 0) {
                unset($this->clientsPerIp[$net]);
            }

            --$this->clientCount;
        }
    }

    public function setUpSocketServer(SocketServer $server): SocketServer
    {
        return $this->delegate->setUpSocketServer($server);
    }
}
