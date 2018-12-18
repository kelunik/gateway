<?php

namespace Kelunik\Gateway;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use function Amp\call;

final class AccessLogMiddleware implements Middleware
{
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Promise
    {
        return call(function () use ($request, $requestHandler) {
            $uri = $request->getUri()->getPath();
            if ($request->getUri()->getQuery() !== '') {
                $uri .= '?' . $request->getUri()->getQuery();
            }

            $remote = $request->getClient()->getRemoteAddress() . ':' . $request->getClient()->getRemotePort();

            try {
                /** @var Response $response */
                $response = yield $requestHandler->handleRequest($request);

                $this->logger->info('[' . $response->getStatus() . '] ' . $request->getMethod() . ' ' . $uri . ' @ ' . $remote);

                return $response;
            } catch (\Throwable $e) {
                $this->logger->error('[500] ' . $request->getMethod() . ' ' . $uri . ' @ ' . $remote);

                throw $e;
            }
        });
    }
}