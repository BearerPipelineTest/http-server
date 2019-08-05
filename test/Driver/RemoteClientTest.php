<?php

namespace Amp\Http\Server\Test\Driver;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\IteratorStream;
use Amp\CancellationToken;
use Amp\Delayed;
use Amp\Emitter;
use Amp\Http\Client\Client as HttpClient;
use Amp\Http\Client\Connection\DefaultConnectionPool;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\Http\Server\Driver\HttpDriverFactory;
use Amp\Http\Server\Driver\RemoteClient;
use Amp\Http\Server\Driver\TimeoutCache;
use Amp\Http\Server\Driver\TimeReference;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestBody;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket;
use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\ServerTlsContext;
use Amp\Success;
use League\Uri;
use League\Uri\Components\Query;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\call;
use function Amp\coroutine;

class RemoteClientTest extends TestCase
{
    public function startServer(callable $handler)
    {
        if (!$server = @\stream_socket_server("tcp://127.0.0.1:*", $errno, $errstr)) {
            $this->markTestSkipped("Couldn't get a free port from the local ephemeral port range");
        }
        $address = \stream_socket_get_name($server, $wantPeer = false);
        \fclose($server);

        $handler = new CallableRequestHandler($handler);
        $tlsContext = (new ServerTlsContext)->withDefaultCertificate(new Certificate(\dirname(__DIR__) . "/server.pem"));

        $servers = [Socket\listen(
            $address,
            (new Socket\BindContext())->withTlsContext($tlsContext)
        )];

        $options = (new Options)->withDebugMode();
        $server = new Server($servers, $handler, $this->createMock(PsrLogger::class), $options);

        yield $server->start();
        return [$address, $server];
    }

    public function testTrivialHttpRequest()
    {
        Loop::run(function () {
            list($address, $server) = yield from $this->startServer(function (Request $req) {
                $this->assertEquals("GET", $req->getMethod());
                $this->assertEquals("/uri", $req->getUri()->getPath());
                $query = new Query($req->getUri()->getQuery());
                $this->assertEquals(["foo" => "bar", "baz" => ["1", "2"]], $query->getPairs());
                $this->assertEquals(["header"], $req->getHeaderArray("custom"));

                $data = \str_repeat("*", 100000);
                $stream = new InMemoryStream("data/" . $data . "/data");

                $res = new Response(Status::OK, [], $stream);

                $res->setCookie(new ResponseCookie("cookie", "with-value"));
                $res->setHeader("custom", "header");

                return $res;
            });

            $connector = new class implements Socket\Connector {
                public function connect(string $uri, ?ConnectContext $context = null, ?CancellationToken $token = null): Promise
                {
                    $context = (new Socket\ConnectContext)
                        ->withTlsContext((new ClientTlsContext(''))->withoutPeerVerification());

                    return (new Socket\DnsConnector)->connect($uri, $context, $token);
                }
            };

            $client = new HttpClient(new DefaultConnectionPool($connector));
            $port = \parse_url($address, PHP_URL_PORT);
            $promise = $client->request(
                (new ClientRequest("https://localhost:$port/uri?foo=bar&baz=1&baz=2", "GET"))->withHeader("custom", "header")
            );

            /** @var \Amp\Http\Client\Response $res */
            $res = yield $promise;
            $this->assertEquals(200, $res->getStatus());
            $this->assertEquals(["header"], $res->getHeaderArray("custom"));
            $body = yield $res->getBody()->buffer();
            $this->assertEquals("data/" . \str_repeat("*", 100000) . "/data", $body);

            Loop::stop();
        });
    }

    public function testClientDisconnect()
    {
        Loop::run(function () {
            list($address, $server) = yield from $this->startServer(function (Request $req) use (&$server) {
                $this->assertEquals("POST", $req->getMethod());
                $this->assertEquals("/", $req->getUri()->getPath());
                $this->assertEquals([], $req->getAllParams());
                $this->assertEquals("body", yield $req->getBody()->buffer());

                $data = "data";
                $data .= \str_repeat("_", $server->getOptions()->getOutputBufferSize() + 1);

                return new Response(Status::OK, [], $data);
            });

            $port = \parse_url($address, PHP_URL_PORT);
            $context = (new Socket\ConnectContext)
                ->withTlsContext((new ClientTlsContext(''))->withoutPeerVerification());

            /** @var Socket\EncryptableSocket $socket */
            $socket = yield Socket\connect("tcp://localhost:$port/", $context);
            yield $socket->setupTls();

            $request = "POST / HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\nContent-Length: 4\r\n\r\nbody";
            yield $socket->write($request);

            $socket->close();

            Loop::delay(100, static function () {
                Loop::stop();
            });
        });
    }

    public function tryRequest(Request $request, callable $requestHandler)
    {
        $driver = $this->createMock(HttpDriver::class);

        $driver->expects($this->once())
            ->method("setup")
            ->willReturnCallback(static function (Client $client, callable $emitter) use (&$emit) {
                $emit = $emitter;
                yield;
            });

        $driver->method("write")
            ->willReturnCallback(coroutine(function (Request $request, Response $written) use (&$response, &$body) {
                $response = $written;
                $body = "";
                while (null !== $part = yield $response->getBody()->read()) {
                    $body .= $part;
                }
            }));

        $factory = $this->createMock(HttpDriverFactory::class);
        $factory->method('selectDriver')
            ->willReturn($driver);

        $options = (new Options)
            ->withDebugMode();

        $client = new RemoteClient(
            \fopen("php://memory", "w"),
            new CallableRequestHandler($requestHandler),
            new DefaultErrorHandler,
            $this->createMock(PsrLogger::class),
            $options,
            new TimeoutCache($this->createMock(TimeReference::class), 10)
        );

        $client->start($factory);

        $emit($request);

        return [$response, $body];
    }

    public function testBasicRequest()
    {
        $request = new Request(
            $this->createMock(Client::class),
            "GET", // method
            Uri\Http::createFromString("http://localhost:80/foo"), // URI
            ["host" => ["localhost"]] // headers
        );

        /** @var \Amp\Http\Server\Response $response */
        list($response, $body) = $this->tryRequest($request, function (Request $req) {
            $this->assertSame("localhost", $req->getHeader("Host"));
            $this->assertSame("/foo", $req->getUri()->getPath());
            $this->assertSame("GET", $req->getMethod());
            $this->assertSame("", yield $req->getBody()->buffer());

            return new Response(Status::OK, ["FOO" => "bar"], "message");
        });

        $this->assertInstanceOf(Response::class, $response);

        $status = Status::OK;
        $this->assertSame($status, $response->getStatus());
        $this->assertSame(Status::getReason($status), $response->getReason());
        $this->assertSame("bar", $response->getHeader("foo"));

        $this->assertSame("message", $body);
    }

    public function testStreamRequest()
    {
        $emitter = new Emitter;

        $request = new Request(
            $this->createMock(Client::class),
            "GET", // method
            Uri\Http::createFromString("http://localhost:80/foo"), // URI
            ["host" => ["localhost"]], // headers
            new RequestBody(new IteratorStream($emitter->iterate())) // body
        );

        $emitter->emit("fooBar");
        $emitter->emit("BUZZ!");
        $emitter->complete();

        /** @var \Amp\Http\Server\Response $response */
        list($response, $body) = $this->tryRequest($request, function (Request $req) {
            $buffer = "";
            while (null !== $chunk = yield $req->getBody()->read()) {
                $buffer .= $chunk;
            }
            return new Response(Status::OK, [], $buffer);
        });

        $this->assertInstanceOf(Response::class, $response);

        $status = Status::OK;
        $this->assertSame($status, $response->getStatus());
        $this->assertSame(Status::getReason($status), $response->getReason());

        $this->assertSame("fooBarBUZZ!", $body);
    }

    /**
     * @dataProvider providePreRequestHandlerRequests
     */
    public function testPreRequestHandlerFailure(Request $request, int $status)
    {
        /** @var \Amp\Http\Server\Response $response */
        list($response) = $this->tryRequest($request, function (Request $req) {
            $this->fail("We should already have failed and never invoke the request handler…");
        });

        $this->assertInstanceOf(Response::class, $response);

        $this->assertEquals($status, $response->getStatus());
    }

    public function providePreRequestHandlerRequests()
    {
        return [
            [
                new Request(
                    $this->createMock(Client::class),
                    "OPTIONS", // method
                    Uri\Http::createFromString("http://localhost:80"), // URI
                    ["host" => ["localhost"]], // headers
                    null // body
                ),
                Status::NO_CONTENT
            ],
            [
                new Request(
                    $this->createMock(Client::class),
                    "TRACE", // method
                    Uri\Http::createFromString("http://localhost:80/"), // URI
                    ["host" => ["localhost"]] // headers
                ),
                Status::METHOD_NOT_ALLOWED
            ],
            [
                new Request(
                    $this->createMock(Client::class),
                    "UNKNOWN", // method
                    Uri\Http::createFromString("http://localhost:80/"), // URI
                    ["host" => ["localhost"]] // headers
                ),
                Status::NOT_IMPLEMENTED
            ],
        ];
    }

    public function testOptionsRequest()
    {
        $request = new Request(
            $this->createMock(Client::class),
            "OPTIONS", // method
            Uri\Http::createFromString("http://localhost:80"), // URI
            ["host" => ["localhost"]], // headers
            null // body
        );

        /** @var \Amp\Http\Server\Response $response */
        list($response) = $this->tryRequest($request, function (Request $req) {
            $this->fail("We should already have failed and never invoke the request handler…");
        });

        $this->assertSame(Status::NO_CONTENT, $response->getStatus());
        $this->assertSame(\implode(", ", (new Options)->getAllowedMethods()), $response->getHeader("allow"));
    }

    public function testError()
    {
        $request = new Request(
            $this->createMock(Client::class),
            "GET", // method
            Uri\Http::createFromString("http://localhost:80/foo"), // URI
            ["host" => ["localhost"]] // headers
        );

        /** @var \Amp\Http\Server\Response $response */
        list($response) = $this->tryRequest($request, function (Request $req) {
            throw new \Exception;
        });

        $this->assertSame(Status::INTERNAL_SERVER_ERROR, $response->getStatus());
    }

    public function testWriterReturningEndsReadingResponse()
    {
        $driver = $this->createMock(HttpDriver::class);

        $driver->expects($this->once())
            ->method("setup")
            ->willReturnCallback(function (Client $client, callable $emitter) use (&$emit) {
                $emit = $emitter;
                yield;
            });

        $driver->method("write")
            ->willReturnCallback(coroutine(function (Request $request, Response $written) use (&$body) {
                $count = 3;
                $body = "";
                while ($count-- && null !== $part = yield $written->getBody()->read()) {
                    $body .= $part;
                }
            }));

        $factory = $this->createMock(HttpDriverFactory::class);
        $factory->method('selectDriver')
            ->willReturn($driver);

        $bodyData = "{data}";

        $options = (new Options)
            ->withDebugMode();

        $body = $this->createMock(InputStream::class);
        $body->expects($this->exactly(3))
            ->method("read")
            ->willReturn(new Success($bodyData));

        $response = new Response(Status::OK, [], $body);

        $requestHandler = $this->createMock(RequestHandler::class);
        $requestHandler->expects($this->once())
            ->method("handleRequest")
            ->willReturn(new Success($response));

        $client = new RemoteClient(
            \fopen("php://memory", "w"),
            $requestHandler,
            new DefaultErrorHandler,
            $this->createMock(PsrLogger::class),
            $options,
            new TimeoutCache($this->createMock(TimeReference::class), 10)
        );

        $client->start($factory);

        $emit(new Request($client, "GET", Uri\Http::createFromString("/")));

        $this->assertSame(\str_repeat($bodyData, 3), $body);
    }

    public function startClient(callable $parser, $socket)
    {
        $driver = $this->createMock(HttpDriver::class);

        $driver->method("setup")
            ->willReturnCallback(function (Client $client, callable $onMessage, callable $writer) use ($parser) {
                yield from $parser($writer);
            });

        $factory = $this->createMock(HttpDriverFactory::class);
        $factory->method('selectDriver')
            ->willReturn($driver);

        $options = (new Options)
            ->withDebugMode();

        $client = new RemoteClient(
            $socket,
            $this->createMock(RequestHandler::class),
            $this->createMock(ErrorHandler::class),
            $this->createMock(PsrLogger::class),
            $options,
            new TimeoutCache($this->createMock(TimeReference::class), 10)
        );

        $client->start($factory);

        return $client;
    }

    public function provideFalseTrueUnixDomainSocket()
    {
        return [
            "tcp-unencrypted" => [false, false],
            //"tcp-encrypted" => [false, true],
            "unix" => [true, false],
        ];
    }

    /**
     * @dataProvider provideFalseTrueUnixDomainSocket
     */
    public function testIO(bool $unixSocket, bool $tls)
    {
        $tlsContext = null;

        if ($tls) {
            $tlsContext = (new Socket\ServerTlsContext)
                ->withDefaultCertificate(new Socket\Certificate(\dirname(__DIR__) . "/server.pem"));
        }

        if ($unixSocket) {
            $uri = \tempnam(\sys_get_temp_dir(), "aerys.") . ".sock";
            $uri = "unix://" . $uri;
            $server = Socket\listen($uri, null, $tlsContext);
        } else {
            $server = Socket\listen("tcp://127.0.0.1:0", null, $tlsContext);
            $uri = $server->getAddress();
        }

        Loop::run(function () use ($server, $uri, $tls) {
            $promise = call(function () use ($server, $tls) {
                /** @var \Amp\Socket\Socket $socket */
                $socket = yield $server->accept();

                if ($tls) {
                    yield $socket->setupTls();
                }

                yield $socket->write("a");

                // give readWatcher a chance
                yield new Delayed(10);

                yield $socket->write("b");

                \stream_socket_shutdown($socket->getResource(), STREAM_SHUT_WR);
                $this->assertEquals("cd", yield $socket->read());
            });

            if ($tls) {
                $tlsContext = (new Socket\ClientTlsContext)->withoutPeerVerification();
                $tlsContext = $tlsContext->toStreamContextArray();
            } else {
                $tlsContext = [];
            }

            $client = \stream_socket_client($uri, $errno, $errstr, 1, STREAM_CLIENT_CONNECT, \stream_context_create($tlsContext));

            $client = $this->startClient(function (callable $write) {
                $this->assertEquals("a", yield);
                $this->assertEquals("b", yield);
                $write("c");
                $write("d");
            }, $client);

            yield $promise;

            $client->close();

            Loop::stop();
        });
    }
}
