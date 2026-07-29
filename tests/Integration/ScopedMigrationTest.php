<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use Liminal\Lib\Database\Migration\MigrationFactory;
use Liminal\Registry\MigrationRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Installing one module must not drag every other module's pending migrations
 * along with it — the behaviour doctrine:migrations:migrate gives by default.
 */
#[CoversNothing]
final class ScopedMigrationTest extends IntegrationTestCase
{
    private const ALPHA = 'Liminal\Tests\Integration\Fixtures\MigrationsAlpha';
    private const BETA = 'Liminal\Tests\Integration\Fixtures\MigrationsBeta';

    private Connection $connection;

    private MigrationFactory $factory;

    protected function setUp(): void
    {
        $this->connection = $this->connection();

        foreach (['test_alpha', 'test_beta', 'core_migration_version'] as $table) {
            $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }

        $registry = new MigrationRegistry();
        $registry->add(self::ALPHA, __DIR__ . '/Fixtures/MigrationsAlpha');
        $registry->add(self::BETA, __DIR__ . '/Fixtures/MigrationsBeta');

        $this->factory = new MigrationFactory($registry);
    }

    public function testUnscopedFactorySeesEveryNamespace(): void
    {
        $migrations = $this->factory->create($this->connection)->getMigrationPlanCalculator()->getMigrations();

        self::assertCount(2, $migrations);
    }

    public function testScopedFactoryListsOnlyItsOwnNamespace(): void
    {
        $versions = $this->versionsFor(self::ALPHA);

        self::assertCount(1, $versions);
        self::assertStringStartsWith(self::ALPHA, $versions[0]);
    }

    public function testMigratingOneNamespaceLeavesTheOtherPending(): void
    {
        $scoped = $this->factory->create($this->connection, self::ALPHA);
        $scoped->getMetadataStorage()->ensureInitialized();

        $plan = $scoped->getMigrationPlanCalculator()->getPlanUntilVersion(
            new Version(self::ALPHA . '\Version20260101000000'),
        );

        self::assertCount(1, $plan);

        $scoped->getMigrator()->migrate($plan, new MigratorConfiguration());

        $tables = $this->connection->createSchemaManager()->listTableNames();

        self::assertContains('test_alpha', $tables);
        self::assertNotContains('test_beta', $tables, 'Beta migrated despite the plan being scoped to Alpha.');
        self::assertCount(1, $this->versionsFor(self::BETA));
    }

    /**
     * @return list<string>
     */
    private function versionsFor(string $namespace): array
    {
        $migrations = $this->factory->create($this->connection, $namespace)
            ->getMigrationPlanCalculator()
            ->getMigrations();

        return array_map(
            static fn ($migration): string => (string) $migration->getVersion(),
            $migrations->getItems(),
        );
    }
}
