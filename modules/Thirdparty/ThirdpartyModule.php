<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty;

use Liminal\Config\Configuration;
use Liminal\Module\Thirdparty\Http\ThirdpartyCreatePageHandler;
use Liminal\Module\Thirdparty\Http\ThirdpartyCreateSubmitHandler;
use Liminal\Module\Thirdparty\Http\ThirdpartyDeleteHandler;
use Liminal\Module\Thirdparty\Http\ThirdpartyDetailHandler;
use Liminal\Module\Thirdparty\Http\ThirdpartyListHandler;
use Liminal\Module\Thirdparty\Http\ThirdpartyUpdateHandler;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\Contract\Module;
use Liminal\Registry\EntityRegistry;
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
 * The thirdparty module: the parties this installation does business with —
 * customers, suppliers, and the prospects that are not yet either.
 *
 * The first business vertical, and therefore the first PRODUCTION consumer of
 * the CompanyScoped machinery: its table is fenced per company by the phase-1
 * filter, stamped by prePersist, guarded by postLoad and the write-once flush
 * gate. Business CRUD goes through the ORM — those protections live nowhere
 * else.
 */
final class ThirdpartyModule implements Module, DefinitionProvider
{
    /** The module's identity: its name, its route prefix and its template namespace. */
    public const string NAME = 'thirdparty';

    public const string MIGRATION_NAMESPACE = 'Liminal\Module\Thirdparty\Migrations';

    public const string ENTITY_NAMESPACE = 'Liminal\Module\Thirdparty\Entity';

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

        // On the request path this time: business reads and writes go through
        // the ORM, because the company fence only exists there.
        $registries->get(EntityRegistry::class)
            ->add(self::ENTITY_NAMESPACE, __DIR__ . '/Entity');

        $registries->get(TemplateRegistry::class)
            ->add(self::NAME, __DIR__ . '/templates');

        $registries->get(TranslationRegistry::class)
            ->add('en', __DIR__ . '/lang/en.php');

        // Statics before dynamics, and {id:\d+} is load-bearing: a bare {id}
        // would also match "create".
        $routes = $registries->get(RouteRegistry::class);
        $routes->get('/thirdparties', ThirdpartyListHandler::class, 'thirdparty.list');
        $routes->get('/thirdparties/create', ThirdpartyCreatePageHandler::class, 'thirdparty.create');
        $routes->post('/thirdparties/create', ThirdpartyCreateSubmitHandler::class, 'thirdparty.create_submit');
        $routes->get('/thirdparties/{id:\d+}', ThirdpartyDetailHandler::class, 'thirdparty.detail');
        $routes->post('/thirdparties/{id:\d+}', ThirdpartyUpdateHandler::class, 'thirdparty.update');
        $routes->post('/thirdparties/{id:\d+}/delete', ThirdpartyDeleteHandler::class, 'thirdparty.delete');

        // The first read/write split: read opens the pages, manage the writes,
        // and manage presumes read (every handler authorizes read first).
        $permissions = $registries->get(PermissionRegistry::class);
        $permissions->add(new Permission(ThirdpartyListHandler::READ, 'thirdparty.permission.read', self::NAME));
        $permissions->add(new Permission(ThirdpartyListHandler::MANAGE, 'thirdparty.permission.manage', self::NAME));

        $triggers = $registries->get(TriggerRegistry::class);
        $triggers->declare('THIRDPARTY_CREATED');
        $triggers->declare('THIRDPARTY_UPDATED');
        $triggers->declare('THIRDPARTY_DELETED');

        $registries->get(MenuRegistry::class)->add(new MenuItem(
            'thirdparty.menu.thirdparties',
            'thirdparty.list',
            ThirdpartyListHandler::READ,
            priority: 930,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function definitions(Configuration $config): array
    {
        // The repository's constructor takes only container services
        // (EntityManagerInterface, CompanyContext): autowiring covers it.
        return [];
    }
}
