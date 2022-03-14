<?php

namespace Amp\Http\Server;

use Amp\CompositeException;
use Amp\Future;
use Amp\Http\Server\Driver\ClientHandler;
use Amp\Http\Server\Driver\ConnectionLimitingClientHandler;
use Amp\Http\Server\Driver\SocketClientHandler;
use Amp\Http\Server\Internal\PerformanceRecommender;
use Amp\Http\Server\Middleware\CompressionMiddleware;
use Amp\Socket;
use Amp\Socket\SocketServer;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;

final class HttpSocketServer implements HttpServer
{
    private HttpServerStatus $status = HttpServerStatus::Stopped;

    private readonly Options $options;

    private readonly ErrorHandler $errorHandler;

    private readonly ClientHandler $clientHandler;

    /** @var SocketServer[] */
    private readonly array $sockets;

    /** @var list<\Closure(ServerLifecycle):void> */
    private array $onStart = [];

    /** @var list<\Closure(ServerLifecycle):void> */
    private array $onStop = [];

    /**
     * @param SocketServer[] $sockets
     * @param PsrLogger $logger
     * @param Options|null $options Null creates an Options object with all default options.
     */
    public function __construct(
        array $sockets,
        private readonly PsrLogger $logger,
        ?Options $options = null,
        ?ErrorHandler $errorHandler = null,
        ?ClientHandler $clientHandler = null,
    ) {
        if (!$sockets) {
            throw new \ValueError('Argument #1 ($sockets) can\'t be an empty array');
        }

        foreach ($sockets as $socket) {
            if (!$socket instanceof SocketServer) {
                throw new \TypeError(\sprintf('Argument #1 ($sockets) must be of type array<%s>', SocketServer::class));
            }
        }

        $this->options = $options ?? new Options;
        $this->sockets = $sockets;
        $this->errorHandler = $errorHandler ?? new DefaultErrorHandler;
        $this->clientHandler = $clientHandler ?? new ConnectionLimitingClientHandler(new SocketClientHandler);

        $this->onStart((new PerformanceRecommender())->onStart(...));
    }

    /**
     * Retrieve the current server status.
     */
    public function getStatus(): HttpServerStatus
    {
        return $this->status;
    }

    /**
     * Retrieve the server options object.
     */
    public function getOptions(): Options
    {
        return $this->options;
    }

    /**
     * Retrieve the error handler.
     */
    public function getErrorHandler(): ErrorHandler
    {
        return $this->errorHandler;
    }

    /**
     * Retrieve the logger.
     */
    public function getLogger(): PsrLogger
    {
        return $this->logger;
    }

    public function onStart(\Closure $onStart): void
    {
        $this->onStart[] = $onStart;
    }

    public function onStop(\Closure $onStop): void
    {
        $this->onStop[] = $onStop;
    }

    /**
     * Start the server.
     */
    public function start(RequestHandler $requestHandler): void
    {
        if ($this->status !== HttpServerStatus::Stopped) {
            throw new \Error("Cannot start server: " . $this->status->getLabel());
        }

        if ($this->options->isCompressionEnabled()) {
            if (!\extension_loaded('zlib')) {
                $this->logger->warning(
                    "The zlib extension is not loaded which prevents using compression. " .
                    "Either activate the zlib extension or disable compression in the server's options."
                );
            } else {
                $requestHandler = Middleware\stack($requestHandler, new CompressionMiddleware);
            }
        }

        $this->logger->debug("Starting server");
        $this->status = HttpServerStatus::Starting;

        $futures = [];
        foreach ($this->onStart as $onStart) {
            $futures[] = async($onStart, $this);
        }

        [$exceptions] = Future\awaitAll($futures);

        if (!empty($exceptions)) {
            try {
                $this->stop();
            } finally {
                throw new CompositeException($exceptions, "Server lifecycle onStart failure");
            }
        }

        $this->logger->info("Started server");
        $this->status = HttpServerStatus::Started;

        foreach ($this->sockets as $socket) {
            $socket = $this->clientHandler->setUpSocketServer($socket);

            $scheme = $socket->getBindContext()?->getTlsContext() !== null ? 'https' : 'http';
            $serverName = $socket->getAddress()->toString();

            $this->logger->info("Listening on {$scheme}://{$serverName}/");

            EventLoop::queue(function () use ($requestHandler, $socket): void {
                while ($client = $socket->accept()) {
                    EventLoop::queue(fn () => $this->accept($requestHandler, $client));
                }
            });
        }
    }

    private function accept(RequestHandler $requestHandler, Socket\EncryptableSocket $client): void
    {
        try {
            $this->clientHandler->handleClient(
                $requestHandler,
                $this->errorHandler,
                $this->options,
                $this->logger,
                $client,
            );
        } catch (\Throwable $exception) {
            $this->logger->debug("Exception while handling client {address}", [
                'address' => $client->getRemoteAddress(),
                'exception' => $exception,
            ]);

            $client->close();
        }
    }

    /**
     * Stop the server.
     */
    public function stop(): void
    {
        if ($this->status === HttpServerStatus::Stopped) {
            return;
        }

        if ($this->status !== HttpServerStatus::Started) {
            throw new \Error("Cannot stop server: " . $this->status->getLabel());
        }

        $this->logger->info("Stopping server");
        $this->status = HttpServerStatus::Stopping;

        $futures = [];
        foreach ($this->onStop as $onStop) {
            $futures[] = async($onStop, $this);
        }

        [$exceptions] = Future\awaitAll($futures);

        foreach ($this->sockets as $socket) {
            $socket->close();
        }

        $this->logger->debug("Stopped server");
        $this->status = HttpServerStatus::Stopped;

        if (!empty($exceptions)) {
            throw new CompositeException($exceptions, "Server lifecycle onStop failure");
        }
    }
}