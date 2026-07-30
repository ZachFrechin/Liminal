<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit;

use Liminal\Exception\KernelException;
use Liminal\Kernel;
use Liminal\Registry\HookRegistry;
use Liminal\Registry\ModuleRegistry;
use Liminal\Registry\RouteRegistry;
use Liminal\Registry\TemplateRegistry;
use Liminal\Registry\TranslationRegistry;
use Liminal\Registry\TriggerRegistry;
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

    public function testADeclaredModuleContributesAndLandsInTheModuleRegistry(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/modules');

        $modules = $kernel->registries()->get(ModuleRegistry::class);

        self::assertTrue($modules->has('fixture_module'));
        self::assertSame('1.0.0', $modules->get('fixture_module')->version());
        // Its contribution ran too: the named route is resolvable.
        self::assertNotNull($kernel->registries()->get(RouteRegistry::class)->named('fixture_module.ping'));
    }

    /**
     * The declaration layering contract: modules contribute after every lib,
     * so a module's definition wins the last-wins merge.
     */
    public function testAModuleDefinitionOverridesALibDefinition(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/modules');

        self::assertSame('hello from module', $kernel->container()->get('fixture.greeting'));
    }

    public function testAClassThatIsNotAModuleIsRefused(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/not-a-module');

        $this->expectException(KernelException::class);
        $this->expectExceptionMessageMatches('/must implement/');

        $kernel->boot();
    }

    public function testAMissingModuleClassIsRefused(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/module-missing');

        $this->expectException(KernelException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $kernel->boot();
    }

    public function testAModuleWithAnInvalidNameIsRefused(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/bad-module-name');

        $this->expectException(KernelException::class);
        $this->expectExceptionMessageMatches('/invalid name/');

        $kernel->boot();
    }

    public function testAModuleDeclaringAnUnregisteredMigrationNamespaceFailsTheBoot(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/module-unregistered-migrations');

        $this->expectException(KernelException::class);
        $this->expectExceptionMessageMatches('/never registered it in the MigrationRegistry/');

        $kernel->boot();
    }

    /**
     * The registry loops in definitions()/mergeDefinitions() cover every
     * registry mechanically — including each phase's newcomers: bound as the
     * same frozen instances, and reserved against redefinition.
     */
    public function testTheRenderingRegistriesAreAutoBound(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/providing');

        self::assertSame(
            $kernel->registries()->get(TemplateRegistry::class),
            $kernel->container()->get(TemplateRegistry::class),
        );
        self::assertSame(
            $kernel->registries()->get(TranslationRegistry::class),
            $kernel->container()->get(TranslationRegistry::class),
        );
    }

    public function testTheListenerRegistriesAreAutoBound(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/providing');

        self::assertSame(
            $kernel->registries()->get(HookRegistry::class),
            $kernel->container()->get(HookRegistry::class),
        );
        self::assertSame(
            $kernel->registries()->get(TriggerRegistry::class),
            $kernel->container()->get(TriggerRegistry::class),
        );
    }
}
