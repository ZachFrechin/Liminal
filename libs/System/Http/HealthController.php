<?php

declare(strict_types=1);

namespace Liminal\Lib\System\Http;

use Liminal\Http\JsonResponseFactory;
use Liminal\Registry\RouteRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The "/" liveness endpoint: proves the pipeline runs and the registries were
 * filled, without touching anything that could itself be down.
 */
final readonly class HealthController implements RequestHandlerInterface
{
    public function __construct(
        private JsonResponseFactory $json,
        private RouteRegistry $routes,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json->response(200, [
            'status' => 'ok',
            'routes' => count($this->routes->all()),
        ]);
    }
}
