<?php

declare(strict_types=1);

namespace Liminal\Module\Companies;

use Liminal\Config\Configuration;
use Liminal\Lib\Database\DeferredConnection;
use Liminal\Lib\Module\ModuleManager;
use Liminal\Module\Companies\Administration\CompanyAdministration;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\Contract\Module;
use Liminal\Registry\RegistryCollection;
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
        // Routes, templates, catalogue, permission and menu arrive with the
        // screens; the manifest alone is already a complete, installable
        // module — which is precisely what this phase proves.
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
