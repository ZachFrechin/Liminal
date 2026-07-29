<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit;

use Liminal\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/constructible without arguments/');

        $kernel->boot();
    }

    public function testRedefiningAKernelServiceIsRefused(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/reserved');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/may not redefine kernel service/');

        $kernel->boot();
    }

    public function testAMissingLibClassIsRefused(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/missing');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $kernel->boot();
    }
}
