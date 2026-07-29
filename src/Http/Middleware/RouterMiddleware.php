<?php

declare(strict_types=1);

namespace Liminal\Http\Middleware;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\RouteMatch;
use Liminal\Http\RouteMatchStatus;
use Liminal\Http\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the route and stores the match as a request attribute for the dispatcher.
 * Routing failures become HttpExceptions handled by the ErrorHandlerMiddleware.
 */
final readonly class RouterMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'liminal.route';

    public function __construct(private Router $router) {}

    /**
     * @throws HttpException as notFound or methodNotAllowed, rendered by the error handler
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $match = $this->router->match($request);

        $request = match ($match->status) {
            RouteMatchStatus::Found => $this->withArguments($request, $match),
            RouteMatchStatus::NotFound => throw HttpException::notFound($request->getUri()->getPath()),
            RouteMatchStatus::MethodNotAllowed => throw HttpException::methodNotAllowed($match->allowedMethods),
        };

        return $handler->handle($request);
    }

    private function withArguments(ServerRequestInterface $request, RouteMatch $match): ServerRequestInterface
    {
        foreach ($match->arguments as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $request->withAttribute(self::ATTRIBUTE, $match);
    }
}
