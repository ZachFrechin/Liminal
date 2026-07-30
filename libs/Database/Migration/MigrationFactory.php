<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\MigrationPlanCalculator;
use Doctrine\Migrations\Version\SortedMigrationPlanCalculator;
use Liminal\Registry\MigrationRegistry;

/**
 * Builds a DependencyFactory from every migration namespace the modules
 * contributed, optionally restricted to one of them.
 *
 * The restriction matters at install time: doctrine's migrate command runs every
 * registered namespace, so enabling one module would silently apply every other
 * module's pending migrations. Passing $namespace swaps in ScopedPlanCalculator so
 * only that module's migrations are planned.
 */
final readonly class MigrationFactory
{
    public function __construct(private MigrationRegistry $migrations) {}

    public function create(Connection $connection, ?string $namespace = null): DependencyFactory
    {
        $factory = DependencyFactory::fromConnection(
            new ConfigurationArray([
                'migrations_paths' => $this->migrations->all(),
                'table_storage' => [
                    'table_name' => 'core_migration_version',
                ],
                // MariaDB commits DDL implicitly: wrapping migrations in a
                // transaction is a lie there, and worse — the phantom commit
                // desyncs DBAL's savepoint bookkeeping, breaking every later
                // transactional() call on the same connection. Migrations that
                // need atomic DML manage their own transaction explicitly.
                'all_or_nothing' => false,
                'transactional' => false,
            ]),
            new ExistingConnection($connection),
        );

        // Without this, ordering across namespaces is alphabetical on class
        // names — an accident of spelling that a third-party module would break.
        $migrations = $this->migrations;
        $factory->setDefinition(
            Comparator::class,
            static fn(): Comparator => new ContributionOrderComparator($migrations),
        );

        if ($namespace !== null) {
            $factory->setDefinition(
                MigrationPlanCalculator::class,
                static function (DependencyFactory $factory) use ($namespace): MigrationPlanCalculator {
                    return new ScopedPlanCalculator(
                        new SortedMigrationPlanCalculator(
                            $factory->getMigrationRepository(),
                            $factory->getMetadataStorage(),
                            $factory->getVersionComparator(),
                        ),
                        $namespace,
                    );
                },
            );
        }

        return $factory;
    }
}
