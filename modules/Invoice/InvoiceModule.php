<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice;

use Liminal\Config\Configuration;
use Liminal\Module\Invoice\Hook\InvoiceThirdpartyVetoListener;
use Liminal\Module\Invoice\Http\Api\InvoiceApiDetailHandler;
use Liminal\Module\Invoice\Http\Api\InvoiceApiListHandler;
use Liminal\Module\Invoice\Http\InvoiceCreatePageHandler;
use Liminal\Module\Invoice\Http\InvoiceCreateSubmitHandler;
use Liminal\Module\Invoice\Http\InvoiceDeleteHandler;
use Liminal\Module\Invoice\Http\InvoiceDetailHandler;
use Liminal\Module\Invoice\Http\InvoiceLineAddHandler;
use Liminal\Module\Invoice\Http\InvoiceLineRemoveHandler;
use Liminal\Module\Invoice\Http\InvoiceListHandler;
use Liminal\Module\Invoice\Http\InvoiceUpdateHandler;
use Liminal\Module\Invoice\Http\InvoiceValidateHandler;
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
 * The invoice module: customer invoices — the first document vertical, and
 * the home of the first production hook, invoice.total.compute, the name the
 * phase-0 table promised.
 *
 * Depends on the thirdparty module, one direction, on purpose: an invoice
 * names the party it bills, the foreign key materialises the dependency, and
 * hiding it behind raw SQL would not remove it — only make it unreviewable.
 * app.modules orders thirdparty first. The reverse edge stays clean through
 * the thirdparty.deletion.veto hook: thirdparty never learns this module
 * exists.
 */
final class InvoiceModule implements Module, DefinitionProvider
{
    /** The module's identity: its name, its route prefix and its template namespace. */
    public const string NAME = 'invoice';

    public const string MIGRATION_NAMESPACE = 'Liminal\Module\Invoice\Migrations';

    public const string ENTITY_NAMESPACE = 'Liminal\Module\Invoice\Entity';

    /**
     * The permission pair lives on the module: the read/manage split predates
     * its screens (labels must render on the role forms from day one).
     */
    public const string READ = 'invoice.read';

    public const string MANAGE = 'invoice.manage';

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
        $routes->get('/invoices', InvoiceListHandler::class, 'invoice.list');
        $routes->get('/invoices/create', InvoiceCreatePageHandler::class, 'invoice.create');
        $routes->post('/invoices/create', InvoiceCreateSubmitHandler::class, 'invoice.create_submit');
        $routes->get('/invoices/{id:\d+}', InvoiceDetailHandler::class, 'invoice.detail');
        $routes->post('/invoices/{id:\d+}', InvoiceUpdateHandler::class, 'invoice.update');
        $routes->post('/invoices/{id:\d+}/lines', InvoiceLineAddHandler::class, 'invoice.line_add');
        $routes->post('/invoices/{id:\d+}/lines/{line:\d+}/remove', InvoiceLineRemoveHandler::class, 'invoice.line_remove');
        $routes->post('/invoices/{id:\d+}/validate', InvoiceValidateHandler::class, 'invoice.validate');
        $routes->post('/invoices/{id:\d+}/delete', InvoiceDeleteHandler::class, 'invoice.delete');

        // The JSON face — module-prefixed names: the api routes disable with
        // the module, /api is a path, not an owner.
        $routes->get('/api/v1/invoices', InvoiceApiListHandler::class, 'invoice.api.list');
        $routes->get('/api/v1/invoices/{id:\d+}', InvoiceApiDetailHandler::class, 'invoice.api.detail');

        $registries->get(MenuRegistry::class)->add(new MenuItem(
            'invoice.menu.invoices',
            'invoice.list',
            self::READ,
            priority: 940,
            icon: 'receipt-text',
        ));

        $permissions = $registries->get(PermissionRegistry::class);
        $permissions->add(new Permission(self::READ, 'invoice.permission.read', self::NAME));
        $permissions->add(new Permission(self::MANAGE, 'invoice.permission.manage', self::NAME));

        // The first production hook: the dispatch site owns the base totals,
        // listeners transform them, the declarer validates what comes back.
        $hooks = $registries->get(HookRegistry::class);
        $hooks->declare('invoice.total.compute');
        // The symmetric veto: whoever holds records realised by an invoice
        // (a converted order, someday a payment) answers here — this module
        // never learns who. Only DRAFTS reach the dispatch: validated
        // invoices are refused upstream by the immutability guard.
        $hooks->declare('invoice.deletion.veto');
        // And the first production LISTENER: the thirdparty module declares
        // the veto, this module answers it — the edge points one way only.
        $hooks->listen('thirdparty.deletion.veto', InvoiceThirdpartyVetoListener::class);

        $triggers = $registries->get(TriggerRegistry::class);
        $triggers->declare('INVOICE_CREATED');
        $triggers->declare('INVOICE_UPDATED');
        $triggers->declare('INVOICE_VALIDATED');
        $triggers->declare('INVOICE_DELETED');
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
