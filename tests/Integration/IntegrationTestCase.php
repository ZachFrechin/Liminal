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
}
