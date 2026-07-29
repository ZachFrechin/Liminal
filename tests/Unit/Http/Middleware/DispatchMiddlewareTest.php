<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Http\Middleware;

use Liminal\Http\Exception\InvalidHandlerException;
use Liminal\Http\Middleware\DispatchMiddleware;
use Liminal\Http\Middleware\RouterMiddleware;
use Liminal\Http\RouteMatch;
use Liminal\Registry\Route;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(DispatchMiddleware::class)]
final class DispatchMiddlewareTest extends TestCase
{
    public function testTheMatchedHandlerIsResolvedAndRun(): void
    {
        $response = new Psr17Factory()->createResponse(204);

        $handler = new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $middleware = new DispatchMiddleware($this->containerWith(['the-handler' => $handler]));

        $request = new Psr17Factory()->createServerRequest('GET', '/')
            ->withAttribute(RouterMiddleware::ATTRIBUTE, RouteMatch::found(new Route('GET', '/', 'the-handler'), []));

        self::assertSame(204, $middleware->process($request, $this->neverCalled())->getStatusCode());
    }

    public function testAMissingRouteMatchIsAWiringError(): void
    {
        $middleware = new DispatchMiddleware($this->containerWith([]));

        $this->expectException(InvalidHandlerException::class);

        $middleware->process(new Psr17Factory()->createServerRequest('GET', '/'), $this->neverCalled());
    }

    public function testAServiceThatIsNotAHandlerIsRefused(): void
    {
        $middleware = new DispatchMiddleware($this->containerWith(['the-handler' => 'just a string']));

        $request = new Psr17Factory()->createServerRequest('GET', '/')
            ->withAttribute(RouterMiddleware::ATTRIBUTE, RouteMatch::found(new Route('GET', '/', 'the-handler'), []));

        $this->expectException(InvalidHandlerException::class);

        $middleware->process($request, $this->neverCalled());
    }

    /**
     * @param array<string, mixed> $services
     */
    private function containerWith(array $services): ContainerInterface
    {
        return new class ($services) implements ContainerInterface {
            /** @param array<string, mixed> $services */
            public function __construct(private readonly array $services) {}

            public function get(string $id): mixed
            {
                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
    }

    private function neverCalled(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                TestCase::fail('DispatchMiddleware must never delegate further.');
            }
        };
    }
}
