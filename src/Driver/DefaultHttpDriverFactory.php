<?php

namespace Amp\Http\Server\Driver;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Options;
use Amp\Http\Server\RequestHandler;
use Psr\Log\LoggerInterface;

final class DefaultHttpDriverFactory implements HttpDriverFactory
{
    private RequestHandler $requestHandler;
    private ErrorHandler $errorHandler;
    private LoggerInterface $logger;
    private Options $options;

    public function setup(HttpServer $server): void {
        $this->requestHandler = $server->getRequestHandler();
        $this->errorHandler = $server->getErrorHandler();
        $this->logger = $server->getLogger();
        $this->options = $server->getOptions();
    }

    public function getApplicationLayerProtocols(): array
    {
        return ["h2", "http/1.1"];
    }

    public function createHttpDriver(Client $client): HttpDriver
    {
        if ($client->getTlsInfo()?->getApplicationLayerProtocol() === "h2") {
            return new Http2Driver(
                $this->requestHandler,
                $this->errorHandler,
                $this->logger,
                $this->options
            );
        }

        return new Http1Driver(
            $this->requestHandler,
            $this->errorHandler,
            $this->logger,
            $this->options,
        );
    }
}
