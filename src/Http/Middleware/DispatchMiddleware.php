<?php

declare(strict_types=1);

namespace Liminal\Http\Middleware;

use Liminal\Http\Exception\InvalidHandlerException;
use Liminal\Http\RouteMatch;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Innermost middleware: resolves the matched handler from the container and runs it.
 * Never delegates further — the pipeline ends here.
 */
final readonly class DispatchMiddleware implements MiddlewareInterface
{
    public function __construct(private ContainerInterface $container) {}

    /**
     * @throws InvalidHandlerException when no route match reached this point or the service is not a handler
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $match = $request->getAttribute(RouterMiddleware::ATTRIBUTE);

        if (!$match instanceof RouteMatch) {
            throw InvalidHandlerException::missingRouteMatch();
        }

        $resolved = $this->container->get($match->handler);

        if (!$resolved instanceof RequestHandlerInterface) {
            throw InvalidHandlerException::notARequestHandler($match->handler);
        }

        return $resolved->handle($request);
    }
}
