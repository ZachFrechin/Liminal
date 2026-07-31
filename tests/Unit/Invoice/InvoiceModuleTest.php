<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Invoice;

use Liminal\Module\Invoice\InvoiceModule;
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

#[CoversClass(InvoiceModule::class)]
final class InvoiceModuleTest extends TestCase
{
    public function testTheManifestNamesTheMigrationNamespaceItRegisters(): void
    {
        $module = new InvoiceModule();
        $migrations = new MigrationRegistry();

        // The boot asserts these agree; prove they do without booting.
        $module->contribute($this->registriesWith($migrations));

        self::assertArrayHasKey($module->migrationNamespace(), $migrations->all());
    }

    public function testTheModuleNameIsTheSlugTheKernelAccepts(): void
    {
        self::assertSame('invoice', new InvoiceModule()->name());
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', new InvoiceModule()->name());
    }

    /**
     * The boot enforces this, but a unit test says which route broke without
     * standing up a kernel first.
     */
    public function testEveryContributedRouteCarriesTheModulePrefix(): void
    {
        $module = new InvoiceModule();
        $routes = new RouteRegistry();

        $module->contribute($this->registriesWith(new MigrationRegistry(), $routes));

        self::assertNotSame([], $routes->all());

        foreach ($routes->all() as $route) {
            self::assertIsString($route->name);
            self::assertStringStartsWith($module->name() . '.', $route->name);
        }
    }

    public function testTheProductionHookAndTheTriggersAreDeclared(): void
    {
        $hooks = new HookRegistry();
        $triggers = new TriggerRegistry();

        new InvoiceModule()->contribute($this->registriesWith(new MigrationRegistry(), hooks: $hooks, triggers: $triggers));

        // The name the phase-0 table promised, verbatim — and the symmetric
        // veto any module holding records realised by an invoice answers.
        self::assertTrue($hooks->has('invoice.total.compute'));
        self::assertTrue($hooks->has('invoice.deletion.veto'));
        self::assertSame(
            ['INVOICE_CREATED', 'INVOICE_UPDATED', 'INVOICE_VALIDATED', 'INVOICE_DELETED'],
            $triggers->names(),
        );

        // And the first production listener: this module answers the
        // thirdparty module's deletion veto — the edge points one way.
        $listeners = $hooks->listenersFor('thirdparty.deletion.veto');
        self::assertCount(1, $listeners);
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
