<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice;

use Liminal\Config\Configuration;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\Contract\Module;
use Liminal\Registry\EntityRegistry;
use Liminal\Registry\HookRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\Permission;
use Liminal\Registry\PermissionRegistry;
use Liminal\Registry\RegistryCollection;
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

        $registries->get(TranslationRegistry::class)
            ->add('en', __DIR__ . '/lang/en.php');

        $permissions = $registries->get(PermissionRegistry::class);
        $permissions->add(new Permission(self::READ, 'invoice.permission.read', self::NAME));
        $permissions->add(new Permission(self::MANAGE, 'invoice.permission.manage', self::NAME));

        // The first production hook: the dispatch site owns the base totals,
        // listeners transform them, the declarer validates what comes back.
        $registries->get(HookRegistry::class)->declare('invoice.total.compute');

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
