<?php

namespace Amp\Http\Server\Test;

use Amp\Artax\Client;
use Amp\Artax\DefaultClient;
use Amp\Delayed;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use Amp\Socket;
use Psr\Log\LoggerInterface as PsrLogger;

class ServerTest extends TestCase
{
    public function testEmptySocketArray()
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Argument 1 can\'t be an empty array');
        new Server([], new CallableRequestHandler(function () {
            return new Response;
        }), $this->createMock(PsrLogger::class));
    }

    public function testShutdownWaitsOnUnfinishedResponses()
    {
        $socket = Socket\listen("tcp://127.0.0.1:0");
        $server = new Server([$socket], new CallableRequestHandler(function () {
            yield new Delayed(2000);

            return new Response(Status::NO_CONTENT);
        }), $this->createMock(PsrLogger::class));

        Promise\wait($server->start());

        $promise = (new DefaultClient)->request("http://" . $socket->getAddress() . "/", [
            Client::OP_TRANSFER_TIMEOUT => 4000,
        ]);

        // Ensure client already connected and sent request
        Promise\wait(new Delayed(1000));
        Promise\wait($server->stop());

        $response = Promise\wait($promise);
        $this->assertSame(Status::NO_CONTENT, $response->getStatus());
    }
}
