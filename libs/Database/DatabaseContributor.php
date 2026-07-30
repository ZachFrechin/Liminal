<?php

declare(strict_types=1);

namespace Liminal\Lib\Database;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Liminal\Config\Configuration;
use Liminal\Lib\Database\Console\MigrateCommand;
use Liminal\Lib\Database\Console\MigrateStatusCommand;
use Liminal\Lib\Database\Health\DatabaseHealth;
use Liminal\Lib\Database\Install\FirstCompanySeeder;
use Liminal\Lib\Database\Migration\MigrationFactory;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Database\Scope\CompanyDirectory;
use Liminal\Registry\CommandRegistry;
use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\EntityRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\RegistryCollection;
use Psr\Container\ContainerInterface;

/**
 * lib/database contributes exactly like any module would: it registers its own
 * entity and migration namespaces, and exposes its services as lazy container
 * definitions.
 *
 * Everything below is deferred on purpose. Resolving the EntityManager reads
 * the DSN but performs no I/O (DBAL connects on the first query), and the
 * doctor's connection closure only resolves when a check actually runs — so a
 * missing LIMINAL_DSN never breaks boot, only the features that need it.
 */
final class DatabaseContributor implements Contributor, DefinitionProvider
{
    public const ENTITY_NAMESPACE = 'Liminal\Lib\Database\Entity';

    public const MIGRATION_NAMESPACE = 'Liminal\Lib\Database\Migrations';

    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(EntityRegistry::class)
            ->add(self::ENTITY_NAMESPACE, __DIR__ . '/Entity');

        $registries->get(MigrationRegistry::class)
            ->add(self::MIGRATION_NAMESPACE, __DIR__ . '/Migrations');

        $commands = $registries->get(CommandRegistry::class);
        $commands->add(MigrateCommand::class);
        $commands->add(MigrateStatusCommand::class);
    }

    /**
     * MigrationFactory needs no definition: its only dependency is the
     * MigrationRegistry the kernel already binds, so autowiring covers it.
     *
     * @return array<string, mixed>
     */
    public function definitions(Configuration $config): array
    {
        return [
            // Shared and mutable by design: the auth layer switches companies
            // per request via switchTo() on this very instance, and the
            // EntityManager's scope listener follows it.
            CompanyContext::class => static fn(): CompanyContext
                => new CompanyContext($config->int('database.bootstrap_company_id')),

            EntityManagerInterface::class => static fn(
                EntityRegistry $entities,
                CompanyContext $context,
            ): EntityManagerInterface
                => new EntityManagerFactory($entities, $context)->create($config->string('database.url')),

            // ORM, migrations and the doctor share this one connection.
            Connection::class => static fn(EntityManagerInterface $entityManager): Connection
                => $entityManager->getConnection(),

            DatabaseHealth::class => static fn(ContainerInterface $container): DatabaseHealth
                => new DatabaseHealth($config, DeferredConnection::resolver($container)),

            // Deferred for the same reason as DatabaseHealth: the console
            // resolves every command eagerly, and commands inject this runner.
            MigrationRunner::class => static fn(
                MigrationFactory $factory,
                MigrationRegistry $migrations,
                ContainerInterface $container,
            ): MigrationRunner
                => new MigrationRunner($factory, $migrations, DeferredConnection::resolver($container)),

            FirstCompanySeeder::class => static fn(ContainerInterface $container): FirstCompanySeeder
                => new FirstCompanySeeder(DeferredConnection::resolver($container)),

            // Deferred like the seeder: the rendering extension injects this,
            // and templates render on DSN-less checkouts (public pages).
            CompanyDirectory::class => static fn(ContainerInterface $container): CompanyDirectory
                => new CompanyDirectory(DeferredConnection::resolver($container)),
        ];
    }

}
