<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit;

use Liminal\Kernel;
use Liminal\Registry\Exception\FrozenRegistryException;
use Liminal\Registry\RouteRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end proof of the phase 0 contract: a request is served by a route that a
 * lib contributed to a registry, with nothing routed from the kernel itself.
 */
#[CoversClass(Kernel::class)]
final class KernelTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testServesARouteContributedByALib(): void
    {
        $response = $this->handle('GET', '/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"status":"ok"', (string) $response->getBody());
    }

    public function testUnknownPathYieldsNotFound(): void
    {
        self::assertSame(404, $this->handle('GET', '/does-not-exist')->getStatusCode());
    }

    public function testWrongMethodYieldsMethodNotAllowedWithAllowHeader(): void
    {
        $response = $this->handle('POST', '/');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
    }

    public function testRegistriesAreFrozenOnceBootCompletes(): void
    {
        $kernel = new Kernel(self::ROOT);
        $kernel->boot();

        self::assertTrue($kernel->registries()->isFrozen());

        $this->expectException(FrozenRegistryException::class);
        $kernel->registries()->get(RouteRegistry::class)->get('/late', 'Handler');
    }

    private function handle(string $method, string $path): \Psr\Http\Message\ResponseInterface
    {
        return (new Kernel(self::ROOT))->handle(
            (new Psr17Factory())->createServerRequest($method, $path),
        );
    }
}
