<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Liminal\Lib\Database\Migration\MigrationFactory;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Registry\MigrationRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The runner against real MariaDB: latest-resolution, idempotence, module
 * scoping, and the promise that a status read never mutates the database.
 */
#[CoversNothing]
final class MigrationRunnerTest extends IntegrationTestCase
{
    private const ALPHA = 'Liminal\Tests\Integration\Fixtures\MigrationsAlpha';
    private const BETA = 'Liminal\Tests\Integration\Fixtures\MigrationsBeta';

    private Connection $connection;

    private MigrationRunner $runner;

    protected function setUp(): void
    {
        $this->connection = $this->connection();

        foreach (['test_alpha', 'test_beta', 'core_migration_version'] as $table) {
            $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }

        $registry = new MigrationRegistry();
        $registry->add(self::ALPHA, __DIR__ . '/Fixtures/MigrationsAlpha');
        $registry->add(self::BETA, __DIR__ . '/Fixtures/MigrationsBeta');

        $connection = $this->connection;
        $this->runner = new MigrationRunner(
            new MigrationFactory($registry),
            $registry,
            static fn(): Connection => $connection,
        );
    }

    public function testMigratingEverythingIsIdempotent(): void
    {
        $executed = $this->runner->migrateToLatest();

        self::assertCount(2, $executed);
        self::assertContains('test_alpha', $this->connection->createSchemaManager()->listTableNames());
        self::assertContains('test_beta', $this->connection->createSchemaManager()->listTableNames());

        self::assertSame([], $this->runner->migrateToLatest());
    }

    public function testAScopedRunLeavesOtherNamespacesPending(): void
    {
        $executed = $this->runner->migrateToLatest(self::ALPHA);

        self::assertCount(1, $executed);
        self::assertStringStartsWith(self::ALPHA, $executed[0]);

        $tables = $this->connection->createSchemaManager()->listTableNames();

        self::assertContains('test_alpha', $tables);
        self::assertNotContains('test_beta', $tables);
        self::assertSame([self::ALPHA => 0, self::BETA => 1], $this->runner->pendingByNamespace());
    }

    /**
     * The doctor doctrine applied to migrations: reading the status of a
     * virgin database must not create the metadata table.
     */
    public function testAStatusReadDoesNotCreateTheMetadataTable(): void
    {
        self::assertSame([self::ALPHA => 1, self::BETA => 1], $this->runner->pendingByNamespace());

        self::assertNotContains(
            'core_migration_version',
            $this->connection->createSchemaManager()->listTableNames(),
        );
    }
}
