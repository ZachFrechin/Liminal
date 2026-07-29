<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Registry;

use Liminal\Registry\Exception\DuplicateContributionException;
use Liminal\Registry\Exception\UnknownModuleException;
use Liminal\Registry\ModuleRegistry;
use Liminal\Tests\Unit\Fixtures\Kernel\FixtureModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleRegistry::class)]
final class ModuleRegistryTest extends TestCase
{
    public function testManifestsAreKeyedByNameInDeclarationOrder(): void
    {
        $module = new FixtureModule();
        $registry = new ModuleRegistry([$module]);

        self::assertTrue($registry->has('fixture_module'));
        self::assertSame($module, $registry->get('fixture_module'));
        self::assertSame(['fixture_module' => $module], $registry->all());
    }

    public function testTwoManifestsClaimingTheSameNameAreRefused(): void
    {
        $this->expectException(DuplicateContributionException::class);

        new ModuleRegistry([new FixtureModule(), new FixtureModule()]);
    }

    public function testLookingUpAnUndeclaredNameThrows(): void
    {
        $registry = new ModuleRegistry();

        self::assertFalse($registry->has('nope'));

        $this->expectException(UnknownModuleException::class);

        $registry->get('nope');
    }
}
