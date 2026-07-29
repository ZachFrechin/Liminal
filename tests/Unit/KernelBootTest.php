<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit;

use Liminal\Exception\KernelException;
use Liminal\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The definition-collection half of the boot contract: libs are plain-new
 * manifests whose definitions() output reaches the container — within the
 * limits the kernel enforces.
 */
#[CoversClass(Kernel::class)]
final class KernelBootTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/Kernel';

    public function testALibCanProvideContainerDefinitions(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/providing');

        self::assertSame('hello from lib', $kernel->container()->get('fixture.greeting'));
    }

    public function testALibNeedingConstructorArgumentsIsRefused(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/needs-arguments');

        $this->expectException(KernelException::class);
        $this->expectExceptionMessageMatches('/constructible without arguments/');

        $kernel->boot();
    }

    public function testRedefiningAKernelServiceIsRefused(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/reserved');

        $this->expectException(KernelException::class);
        $this->expectExceptionMessageMatches('/may not redefine kernel service/');

        $kernel->boot();
    }

    public function testAMissingLibClassIsRefused(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/missing');

        $this->expectException(KernelException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $kernel->boot();
    }

    /**
     * The pipeline contribution contract end to end: a lib registers a
     * middleware and a route, and the middleware's effect reaches the
     * response served through the full kernel stack.
     */
    public function testALibCanContributeMiddlewareToThePipeline(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/middleware');

        $response = $kernel->handle(new Psr17Factory()->createServerRequest('GET', '/'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-Fixture'));
    }

    public function testAMiddlewareBehindTheDispatcherFailsTheBoot(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/misplaced-middleware');

        $this->expectException(KernelException::class);
        $this->expectExceptionMessageMatches('/would never run/');

        $kernel->boot();
    }
}
