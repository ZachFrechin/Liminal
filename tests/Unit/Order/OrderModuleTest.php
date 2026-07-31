<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Order;

use Liminal\Module\Order\OrderModule;
use Liminal\Registry\EntityRegistry;
use Liminal\Registry\HookRegistry;
use Liminal\Registry\MenuRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\PermissionRegistry;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;
use Liminal\Registry\TemplateRegistry;
use Liminal\Registry\TranslationRegistry;
use Liminal\Registry\TriggerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderModule::class)]
final class OrderModuleTest extends TestCase
{
    public function testTheManifestNamesTheMigrationNamespaceItRegisters(): void
    {
        $module = new OrderModule();
        $migrations = new MigrationRegistry();

        // The boot asserts these agree; prove they do without booting.
        $module->contribute($this->registriesWith($migrations));

        self::assertArrayHasKey($module->migrationNamespace(), $migrations->all());
    }

    public function testTheModuleNameIsTheSlugTheKernelAccepts(): void
    {
        self::assertSame('order', new OrderModule()->name());
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', new OrderModule()->name());
    }

    public function testTheTotalsHookAndTheFiveTriggersAreDeclared(): void
    {
        $hooks = new HookRegistry();
        $triggers = new TriggerRegistry();

        new OrderModule()->contribute($this->registriesWith(new MigrationRegistry(), hooks: $hooks, triggers: $triggers));

        self::assertTrue($hooks->has('order.total.compute'));
        self::assertSame(
            ['ORDER_CREATED', 'ORDER_UPDATED', 'ORDER_VALIDATED', 'ORDER_INVOICED', 'ORDER_DELETED'],
            $triggers->names(),
        );
    }

    private function registriesWith(
        MigrationRegistry $migrations,
        ?RouteRegistry $routes = null,
        ?HookRegistry $hooks = null,
        ?TriggerRegistry $triggers = null,
    ): RegistryCollection {
        return new RegistryCollection([
            $migrations,
            $routes ?? new RouteRegistry(),
            new EntityRegistry(),
            new TemplateRegistry(),
            new TranslationRegistry(),
            new PermissionRegistry(),
            new MenuRegistry(),
            $hooks ?? new HookRegistry(),
            $triggers ?? new TriggerRegistry(),
        ]);
    }
}
