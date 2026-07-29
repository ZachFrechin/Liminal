<?php

declare(strict_types=1);

namespace Liminal\Lib\System\Http;

use Liminal\Registry\RouteRegistry;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class HealthController implements RequestHandlerInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private RouteRegistry $routes,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');

        $response->getBody()->write(json_encode([
            'status' => 'ok',
            'routes' => count($this->routes->all()),
        ], JSON_THROW_ON_ERROR));

        return $response;
    }
}
