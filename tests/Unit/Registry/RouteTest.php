<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Registry;

use InvalidArgumentException;
use Liminal\Registry\Route;
use Liminal\Registry\RouteRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Route::class)]
#[CoversClass(RouteRegistry::class)]
final class RouteTest extends TestCase
{
    public function testAValidRouteKeepsItsParts(): void
    {
        $route = new Route('GET', '/thing/{id}', 'Handler', 'thing.show');

        self::assertSame('GET', $route->method);
        self::assertSame('/thing/{id}', $route->path);
        self::assertSame('Handler', $route->handler);
        self::assertSame('thing.show', $route->name);
    }

    public function testAnUnknownMethodIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Route('BANANA', '/thing', 'Handler');
    }

    /**
     * Methods are uppercase by contract — no silent normalisation, so every
     * contributor spells them the same way.
     */
    public function testALowercaseMethodIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Route('get', '/thing', 'Handler');
    }

    public function testAPathMustStartWithASlash(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Route('GET', 'thing', 'Handler');
    }

    public function testEveryVerbHelperRegistersItsMethod(): void
    {
        $registry = new RouteRegistry();
        $registry->get('/a', 'H');
        $registry->post('/a', 'H');
        $registry->put('/a', 'H');
        $registry->patch('/a', 'H');
        $registry->delete('/a', 'H');
        $registry->map('OPTIONS', '/a', 'H');

        self::assertSame(
            ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            array_map(static fn(Route $route): string => $route->method, $registry->all()),
        );
    }
}
