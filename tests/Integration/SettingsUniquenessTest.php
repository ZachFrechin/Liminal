<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use Liminal\Lib\Database\DatabaseContributor;
use Liminal\Lib\Database\Migration\MigrationFactory;
use Liminal\Registry\MigrationRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Proves the core_setting uniqueness holds on real MariaDB, where a plain
 * unique index over nullable columns would allow unlimited (key, NULL, NULL)
 * duplicates — the generated sentinel columns are what close that hole.
 */
#[CoversNothing]
final class SettingsUniquenessTest extends IntegrationTestCase
{
    private const COMPANY = 1;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->connection();

        foreach (['core_session', 'core_setting', 'core_module_company', 'core_module', 'core_company', 'core_migration_version'] as $table) {
            $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }

        $registry = new MigrationRegistry();
        $registry->add(DatabaseContributor::MIGRATION_NAMESPACE, dirname(__DIR__, 2) . '/libs/Database/Migrations');

        $factory = new MigrationFactory($registry)
            ->create($this->connection, DatabaseContributor::MIGRATION_NAMESPACE);
        $factory->getMetadataStorage()->ensureInitialized();

        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion(
            new Version(DatabaseContributor::MIGRATION_NAMESPACE . '\Version20260729000000'),
        );
        $factory->getMigrator()->migrate($plan, new MigratorConfiguration());

        $this->connection->insert('core_company', [
            'code' => 'MAIN',
            'name' => 'Main company',
            'created_at' => '2026-07-29 00:00:00',
            'updated_at' => '2026-07-29 00:00:00',
        ]);
    }

    public function testTheGeneratedColumnsSurvivedDbal(): void
    {
        $row = $this->connection->fetchAssociative('SHOW CREATE TABLE core_setting');

        self::assertIsArray($row);

        $ddl = $row['Create Table'] ?? null;

        self::assertIsString($ddl);
        self::assertStringContainsString('GENERATED ALWAYS', $ddl);
    }

    public function testAGlobalSettingCannotBeDuplicated(): void
    {
        $this->insertSetting(null, null);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertSetting(null, null);
    }

    public function testACompanySettingCannotBeDuplicated(): void
    {
        $this->insertSetting(self::COMPANY, null);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertSetting(self::COMPANY, null);
    }

    public function testTheThreeScopesCoexistForOneKey(): void
    {
        $this->insertSetting(null, null);
        $this->insertSetting(self::COMPANY, null);
        $this->insertSetting(self::COMPANY, 7);

        self::assertEquals(3, $this->connection->fetchOne('SELECT COUNT(*) FROM core_setting'));
    }

    public function testDeletingACompanyCascadesItsSettingsOnly(): void
    {
        $this->insertSetting(null, null);
        $this->insertSetting(self::COMPANY, null);

        $this->connection->executeStatement('DELETE FROM core_company WHERE id = ?', [self::COMPANY]);

        self::assertEquals(1, $this->connection->fetchOne('SELECT COUNT(*) FROM core_setting'));
        self::assertEquals(0, $this->connection->fetchOne('SELECT COUNT(*) FROM core_setting WHERE company_id IS NOT NULL'));
    }

    private function insertSetting(?int $companyId, ?int $userId): void
    {
        $this->connection->insert('core_setting', [
            'setting_key' => 'core.locale',
            'value' => 'fr_FR',
            'scope' => $companyId === null ? 'global' : ($userId === null ? 'company' : 'user'),
            'company_id' => $companyId,
            'user_id' => $userId,
            'created_at' => '2026-07-29 00:00:00',
            'updated_at' => '2026-07-29 00:00:00',
        ]);
    }
}
