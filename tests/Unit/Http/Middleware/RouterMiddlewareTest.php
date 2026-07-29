<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Http\Middleware;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\Middleware\RouterMiddleware;
use Liminal\Http\RouteMatch;
use Liminal\Http\Router;
use Liminal\Registry\RouteRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(RouterMiddleware::class)]
final class RouterMiddlewareTest extends TestCase
{
    public function testAMatchStoresArgumentsAndTheMatchAsAttributes(): void
    {
        $middleware = $this->middlewareWith('GET', '/invoice/{id}');

        $seen = null;

        $next = new class ($seen) implements RequestHandlerInterface {
            public function __construct(public ?ServerRequestInterface $seen) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request;

                return new Psr17Factory()->createResponse(200);
            }
        };

        $middleware->process(new Psr17Factory()->createServerRequest('GET', '/invoice/42'), $next);

        self::assertNotNull($next->seen);
        self::assertSame('42', $next->seen->getAttribute('id'));
        self::assertInstanceOf(RouteMatch::class, $next->seen->getAttribute(RouterMiddleware::ATTRIBUTE));
    }

    public function testAnUnknownPathBecomesA404HttpException(): void
    {
        $middleware = $this->middlewareWith('GET', '/known');

        try {
            $middleware->process(new Psr17Factory()->createServerRequest('GET', '/unknown'), $this->unreachable());
            self::fail('An unknown path must raise an HttpException.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->statusCode());
        }
    }

    public function testAWrongMethodBecomesA405WithTheAllowedMethods(): void
    {
        $middleware = $this->middlewareWith('GET', '/known');

        try {
            $middleware->process(new Psr17Factory()->createServerRequest('POST', '/known'), $this->unreachable());
            self::fail('A wrong method must raise an HttpException.');
        } catch (HttpException $exception) {
            self::assertSame(405, $exception->statusCode());
            self::assertSame(['Allow' => 'GET'], $exception->headers());
        }
    }

    private function middlewareWith(string $method, string $path): RouterMiddleware
    {
        $registry = new RouteRegistry();
        $registry->map($method, $path, 'Handler');

        return new RouterMiddleware(new Router($registry));
    }

    private function unreachable(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                TestCase::fail('The handler must not run when routing fails.');
            }
        };
    }
}
