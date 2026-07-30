<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Liminal\Support\Env;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Base class for tests that need a real SQL server.
 *
 * Integration tests target MariaDB rather than SQLite because the behaviour under
 * test — SQL filters, migrations, real DDL — is precisely what SQLite would fake.
 * With no reachable DSN the suite skips rather than fails, so a developer without a
 * database still gets a meaningful local run; CI always provides one.
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * DsnParser maps unknown schemes verbatim, and "mysql" is not a DBAL driver
     * name, so the mapping below is required rather than cosmetic.
     */
    private const SCHEME_MAPPING = [
        'mysql' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
    ];

    protected function connection(): Connection
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping database integration test.');
        }

        try {
            $connection = DriverManager::getConnection((new DsnParser(self::SCHEME_MAPPING))->parse($dsn));
            $connection->executeQuery('SELECT 1');
        } catch (Throwable $exception) {
            self::markTestSkipped(sprintf('Database at LIMINAL_TEST_DSN is unreachable: %s', $exception->getMessage()));
        }

        return $connection;
    }

    /**
     * Empties the test database by introspection.
     *
     * Hand-maintained drop lists do not survive a schema that grows foreign
     * keys: the moment one test creates a table referencing core_company, every
     * OTHER test's "DROP TABLE core_company" fails — including tests that never
     * knew about the referencing table. Dropping everything with the checks off
     * removes that whole class of cross-test breakage, and each test then
     * migrates exactly what it needs.
     */
    protected function dropAllTables(Connection $connection): void
    {
        $tables = $connection->createSchemaManager()->listTableNames();

        if ($tables === []) {
            return;
        }

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                $connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
            }
        } finally {
            // Restore even if a drop failed: the connection is reused by the
            // very test that just failed, and leaving the checks off would
            // silently mask referential bugs in its assertions.
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
