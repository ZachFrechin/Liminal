<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Http;

use Liminal\Http\RouteMatchStatus;
use Liminal\Http\Router;
use Liminal\Registry\RouteRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
final class RouterTest extends TestCase
{
    public function testMatchesARegisteredRoute(): void
    {
        $match = $this->route('GET', '/health', 'GET', '/health');

        self::assertSame(RouteMatchStatus::Found, $match->status);
        self::assertSame('Handler', $match->handler);
    }

    public function testExposesPathArgumentsAsStrings(): void
    {
        $match = $this->route('GET', '/invoice/{id}', 'GET', '/invoice/42');

        self::assertSame(RouteMatchStatus::Found, $match->status);
        self::assertSame(['id' => '42'], $match->arguments);
    }

    public function testReportsUnknownPathsAsNotFound(): void
    {
        self::assertSame(RouteMatchStatus::NotFound, $this->route('GET', '/health', 'GET', '/nope')->status);
    }

    public function testReportsAllowedMethodsOnMethodMismatch(): void
    {
        $match = $this->route('GET', '/health', 'POST', '/health');

        self::assertSame(RouteMatchStatus::MethodNotAllowed, $match->status);
        self::assertSame(['GET'], $match->allowedMethods);
    }

    private function route(
        string $registeredMethod,
        string $registeredPath,
        string $requestMethod,
        string $requestPath,
    ): \Liminal\Http\RouteMatch {
        $registry = new RouteRegistry();
        $registry->add(new \Liminal\Registry\Route($registeredMethod, $registeredPath, 'Handler'));

        return (new Router($registry))->match(
            (new Psr17Factory())->createServerRequest($requestMethod, $requestPath),
        );
    }
}
