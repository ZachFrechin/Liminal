<?php

declare(strict_types=1);

namespace Liminal\Module\Companies;

use Liminal\Config\Configuration;
use Liminal\Lib\Database\DeferredConnection;
use Liminal\Lib\Module\ModuleManager;
use Liminal\Module\Companies\Administration\CompanyAdministration;
use Liminal\Module\Companies\Http\CompanyCreatePageHandler;
use Liminal\Module\Companies\Http\CompanyCreateSubmitHandler;
use Liminal\Module\Companies\Http\CompanyDetailHandler;
use Liminal\Module\Companies\Http\CompanyListHandler;
use Liminal\Module\Companies\Http\CompanyRenameHandler;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\Contract\Module;
use Liminal\Registry\MenuItem;
use Liminal\Registry\MenuRegistry;
use Liminal\Registry\Permission;
use Liminal\Registry\PermissionRegistry;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;
use Liminal\Registry\TemplateRegistry;
use Liminal\Registry\TranslationRegistry;
use Psr\Container\ContainerInterface;

/**
 * The companies module: the UX of the company axis — nothing more.
 *
 * The `core_company` schema belongs to the Database lib (the axis must exist
 * before any module does), so this is the tree's first module with NO
 * migrations: migrationNamespace() returns null, and the module lifecycle
 * carries a migration-less module as an ordinary case, not an exception.
 */
final class CompaniesModule implements Module, DefinitionProvider
{
    /** The module's identity: its name, its route prefix and its template namespace. */
    public const string NAME = 'companies';

    public function name(): string
    {
        return self::NAME;
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function migrationNamespace(): ?string
    {
        // No schema of its own: core_company is the Database lib's table.
        return null;
    }

    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(TemplateRegistry::class)
            ->add(self::NAME, __DIR__ . '/templates');

        $registries->get(TranslationRegistry::class)
            ->add('en', __DIR__ . '/lang/en.php');

        // Statics before dynamics, and {id:\d+} is load-bearing (a bare {id}
        // would also match "create").
        $routes = $registries->get(RouteRegistry::class);
        $routes->get('/companies', CompanyListHandler::class, 'companies.list');
        $routes->get('/companies/create', CompanyCreatePageHandler::class, 'companies.create');
        $routes->post('/companies/create', CompanyCreateSubmitHandler::class, 'companies.create_submit');
        $routes->get('/companies/{id:\d+}', CompanyDetailHandler::class, 'companies.company');
        $routes->post('/companies/{id:\d+}', CompanyRenameHandler::class, 'companies.company_rename');

        $registries->get(PermissionRegistry::class)
            ->add(new Permission(CompanyListHandler::PERMISSION, 'companies.permission.company.manage', self::NAME));

        $registries->get(MenuRegistry::class)->add(new MenuItem(
            'companies.menu.companies',
            'companies.list',
            CompanyListHandler::PERMISSION,
            priority: 920,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function definitions(Configuration $config): array
    {
        return [
            CompanyAdministration::class => static fn(
                ContainerInterface $container,
                ModuleManager $manager,
            ): CompanyAdministration => new CompanyAdministration(
                DeferredConnection::resolver($container),
                $manager,
            ),
        ];
    }
}
