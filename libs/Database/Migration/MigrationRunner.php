<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Migration;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\MigratorConfiguration;
use Liminal\Registry\MigrationRegistry;

/**
 * The one place migrations are planned and executed — the migrate command and
 * the installer both delegate here.
 *
 * The connection arrives as a deferred closure because the console Application
 * resolves every command eagerly: a constructor-injected Connection would make
 * a DSN-less checkout unable to run even `doctor`.
 */
final readonly class MigrationRunner
{
    /**
     * @param Closure(): Connection $connection deferred so a missing DSN fails at run time, not boot
     */
    public function __construct(
        private MigrationFactory $factory,
        private MigrationRegistry $registry,
        private Closure $connection,
    ) {}

    /**
     * Migrates every registered namespace — or just one — up to latest.
     *
     * The 'latest' alias never throws: with nothing registered it resolves to
     * Version('0'), which plans to an empty list. all_or_nothing must be copied
     * from the configuration by hand — the Migrator only honours what the
     * MigratorConfiguration carries. (MariaDB commits DDL implicitly, so the
     * protection only covers DML migrations anyway.)
     *
     * @return list<string> executed migration versions in execution order; empty when up to date
     */
    public function migrateToLatest(?string $namespace = null): array
    {
        $factory = $this->factory->create(($this->connection)(), $namespace);
        $factory->getMetadataStorage()->ensureInitialized();

        $version = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($version);

        $configuration = new MigratorConfiguration()
            ->setAllOrNothing($factory->getConfiguration()->isAllOrNothing());

        return array_keys($factory->getMigrator()->migrate($plan, $configuration));
    }

    /**
     * Deliberately never calls ensureInitialized(): a status read must not
     * create the metadata table (the doctor doctrine — diagnostics do not
     * mutate what they inspect). An absent table simply reads as "nothing
     * executed yet".
     *
     * @return array<string, int> migration namespace => pending count, zero-filled
     */
    public function pendingByNamespace(): array
    {
        $factory = $this->factory->create(($this->connection)());
        $counts = array_fill_keys(array_keys($this->registry->all()), 0);

        foreach ($factory->getMigrationStatusCalculator()->getNewMigrations()->getItems() as $migration) {
            foreach ($counts as $namespace => $count) {
                if (str_starts_with(ltrim((string) $migration->getVersion(), '\\'), $namespace . '\\')) {
                    ++$counts[$namespace];

                    break;
                }
            }
        }

        return $counts;
    }
}
