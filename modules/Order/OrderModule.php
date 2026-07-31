<?php

declare(strict_types=1);

namespace Liminal\Module\Order;

use Liminal\Config\Configuration;
use Liminal\Module\Order\Http\OrderDetailHandler;
use Liminal\Module\Order\Http\OrderListHandler;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\Contract\Module;
use Liminal\Registry\EntityRegistry;
use Liminal\Registry\HookRegistry;
use Liminal\Registry\MenuItem;
use Liminal\Registry\MenuRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\Permission;
use Liminal\Registry\PermissionRegistry;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;
use Liminal\Registry\TemplateRegistry;
use Liminal\Registry\TranslationRegistry;
use Liminal\Registry\TriggerRegistry;

/**
 * The order module: customer sales orders — the second document vertical,
 * built on the machinery the invoice module proved: the lib money layer,
 * the yearly sequence, the draft-then-freeze lifecycle. One state more than
 * an invoice: a validated order can be invoiced, once, one way.
 *
 * Depends on the thirdparty module (an order names the party it serves) AND
 * on the invoice module (conversion creates a draft invoice) — both edges
 * one-directional and declared; app.modules orders both dependencies first.
 * The reverse directions stay hooks: this module answers
 * thirdparty.deletion.veto and invoice.deletion.veto without either module
 * ever learning it exists.
 */
final class OrderModule implements Module, DefinitionProvider
{
    /** The module's identity: its name, its route prefix and its template namespace. */
    public const string NAME = 'order';

    public const string MIGRATION_NAMESPACE = 'Liminal\Module\Order\Migrations';

    public const string ENTITY_NAMESPACE = 'Liminal\Module\Order\Entity';

    /**
     * The permission pair lives on the module: the read/manage split predates
     * its screens (labels must render on the role forms from day one).
     */
    public const string READ = 'order.read';

    public const string MANAGE = 'order.manage';

    public function name(): string
    {
        return self::NAME;
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function migrationNamespace(): string
    {
        return self::MIGRATION_NAMESPACE;
    }

    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(MigrationRegistry::class)
            ->add(self::MIGRATION_NAMESPACE, __DIR__ . '/Migrations');

        // Business reads and writes go through the ORM, because the company
        // fence only exists there.
        $registries->get(EntityRegistry::class)
            ->add(self::ENTITY_NAMESPACE, __DIR__ . '/Entity');

        $registries->get(TemplateRegistry::class)
            ->add(self::NAME, __DIR__ . '/templates');

        $registries->get(TranslationRegistry::class)
            ->add('en', __DIR__ . '/lang/en.php');

        // Statics before dynamics, and {id:\d+} is load-bearing.
        $routes = $registries->get(RouteRegistry::class);
        $routes->get('/orders', OrderListHandler::class, 'order.list');
        $routes->get('/orders/{id:\d+}', OrderDetailHandler::class, 'order.detail');

        // Between thirdparty (930) and invoice (940): the menu reads the
        // business flow — party, order, invoice.
        $registries->get(MenuRegistry::class)->add(new MenuItem(
            'order.menu.orders',
            'order.list',
            self::READ,
            priority: 935,
            icon: 'shopping-cart',
        ));

        $permissions = $registries->get(PermissionRegistry::class);
        $permissions->add(new Permission(self::READ, 'order.permission.read', self::NAME));
        $permissions->add(new Permission(self::MANAGE, 'order.permission.manage', self::NAME));

        // The order's own totals hook; the veto SUBSCRIPTIONS arrive with
        // their listeners once invoice.deletion.veto is declared — a listen()
        // on an undeclared name breaks every boot at freeze.
        $registries->get(HookRegistry::class)->declare('order.total.compute');

        $triggers = $registries->get(TriggerRegistry::class);
        $triggers->declare('ORDER_CREATED');
        $triggers->declare('ORDER_UPDATED');
        $triggers->declare('ORDER_VALIDATED');
        $triggers->declare('ORDER_INVOICED');
        $triggers->declare('ORDER_DELETED');
    }

    /**
     * @return array<string, mixed>
     */
    public function definitions(Configuration $config): array
    {
        // Every service here takes only container services: autowiring covers it.
        return [];
    }
}
