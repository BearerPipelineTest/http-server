<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\HttpServer;

interface HttpDriverFactory
{
    public function setup(HttpServer $server): void;
    public function createHttpDriver(Client $client): HttpDriver;
}
