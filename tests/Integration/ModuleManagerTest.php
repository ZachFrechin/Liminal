<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Liminal\Lib\Database\DatabaseContributor;
use Liminal\Lib\Database\Migration\MigrationFactory;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Module\Exception\ModuleException;
use Liminal\Lib\Module\ModuleManager;
use Liminal\Registry\Contract\Module;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\ModuleRegistry;
use Liminal\Tests\Integration\Fixtures\ModuleFixture\BareModule;
use Liminal\Tests\Integration\Fixtures\ModuleFixture\IntegrationModule;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The module lifecycle against real MariaDB: install runs the module's own
 * migrations and records state, enablement is per company, and every refusal
 * carries its remedy.
 */
#[CoversNothing]
final class ModuleManagerTest extends IntegrationTestCase
{
    private const COMPANY_A = 1;
    private const COMPANY_B = 2;

    private Connection $dbal;

    protected function setUp(): void
    {
        $this->dbal = $this->connection();

        $this->dropAllTables($this->dbal);

        $core = new MigrationRegistry();
        $core->add(DatabaseContributor::MIGRATION_NAMESPACE, dirname(__DIR__, 2) . '/libs/Database/Migrations');

        $connection = $this->dbal;
        new MigrationRunner(new MigrationFactory($core), $core, static fn(): Connection => $connection)
            ->migrateToLatest();

        $this->dbal->executeStatement('DROP TABLE IF EXISTS core_migration_version');

        foreach ([['MAIN', self::COMPANY_A], ['SECOND', self::COMPANY_B]] as [$code, $id]) {
            $this->dbal->insert('core_company', [
                'id' => $id,
                'code' => $code,
                'name' => $code,
                'created_at' => '2026-07-29 00:00:00',
                'updated_at' => '2026-07-29 00:00:00',
            ]);
        }
    }

    public function testInstallingAModuleRunsItsMigrationsAndRecordsInstalledState(): void
    {
        $outcome = $this->manager(new IntegrationModule())->install('fixture');

        self::assertTrue($outcome->wasFreshInstall());
        self::assertCount(1, $outcome->executedMigrations);
        self::assertContains('test_module_fixture', $this->dbal->createSchemaManager()->listTableNames());

        $row = $this->dbal->fetchAssociative('SELECT name, version, state FROM core_module WHERE name = ?', ['fixture']);

        self::assertIsArray($row);
        self::assertSame('fixture', $row['name']);
        self::assertSame('1.0.0', $row['version']);
        self::assertSame('installed', $row['state']);
    }

    public function testReinstallingIsIdempotentAndUpdatesTheVersion(): void
    {
        $this->manager(new IntegrationModule())->install('fixture');
        $installedAt = $this->dbal->fetchOne('SELECT installed_at FROM core_module WHERE name = ?', ['fixture']);

        $outcome = $this->manager(new IntegrationModule('2.0.0'))->install('fixture');

        self::assertFalse($outcome->wasFreshInstall());
        self::assertTrue($outcome->changedVersion());
        self::assertSame('1.0.0', $outcome->previousVersion);
        self::assertSame([], $outcome->executedMigrations);

        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_module'));
        self::assertEquals('2.0.0', $this->dbal->fetchOne('SELECT version FROM core_module WHERE name = ?', ['fixture']));
        // The first install time survives upgrades, like created_at does.
        self::assertEquals($installedAt, $this->dbal->fetchOne('SELECT installed_at FROM core_module WHERE name = ?', ['fixture']));
    }

    /**
     * The doctor doctrine again: a module without migrations must not even
     * create the migration metadata table on install.
     */
    public function testAModuleWithoutMigrationsInstallsWithoutCreatingTheMetadataTable(): void
    {
        $outcome = $this->manager(new BareModule())->install('bare');

        self::assertTrue($outcome->wasFreshInstall());
        self::assertSame([], $outcome->executedMigrations);
        self::assertNotContains('core_migration_version', $this->dbal->createSchemaManager()->listTableNames());
    }

    public function testInstallingAnUndeclaredModuleIsRefused(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessageMatches('/not declared in app\.modules.*"fixture"/');

        $this->manager(new IntegrationModule())->install('ghost');
    }

    public function testInstallingBeforeTheCoreTablesExistIsRefusedWithTheRemedy(): void
    {
        $this->dbal->executeStatement('DROP TABLE IF EXISTS core_module_company');
        $this->dbal->executeStatement('DROP TABLE IF EXISTS core_module');

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessageMatches('/Run "install"/');

        $this->manager(new IntegrationModule())->install('fixture');
    }

    public function testEnableAndDisableTogglePerCompanyAndKeepTheRow(): void
    {
        $manager = $this->manager(new IntegrationModule());
        $manager->install('fixture');

        $manager->enable('fixture', self::COMPANY_A);

        self::assertTrue($manager->isEnabled('fixture', self::COMPANY_A));
        self::assertFalse($manager->isEnabled('fixture', self::COMPANY_B));

        $manager->disable('fixture', self::COMPANY_A);

        self::assertFalse($manager->isEnabled('fixture', self::COMPANY_A));
        // Disable keeps the row as the audit trail.
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_module_company'));
        self::assertEquals(0, $this->dbal->fetchOne('SELECT enabled FROM core_module_company'));
    }

    public function testEnablingAnUninstalledModuleIsRefused(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessageMatches('/Run "module:install fixture" first/');

        $this->manager(new IntegrationModule())->enable('fixture', self::COMPANY_A);
    }

    public function testEnablingForAMissingCompanyIsRefused(): void
    {
        $manager = $this->manager(new IntegrationModule());
        $manager->install('fixture');

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessageMatches('/No company with id 99/');

        $manager->enable('fixture', 99);
    }

    public function testDeletingACompanyCascadesItsEnablementRows(): void
    {
        $manager = $this->manager(new IntegrationModule());
        $manager->install('fixture');
        $manager->enable('fixture', self::COMPANY_A);

        $this->dbal->executeStatement('DELETE FROM core_company WHERE id = ?', [self::COMPANY_A]);

        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_module_company'));
        self::assertFalse($manager->isEnabled('fixture', self::COMPANY_A));
    }

    public function testOverviewShowsDeclaredInstalledAndOrphanedModules(): void
    {
        $manager = $this->manager(new IntegrationModule(), new BareModule());
        $manager->install('fixture');
        $manager->enable('fixture', self::COMPANY_A);

        // An installed row whose module left app.modules must stay visible.
        $this->dbal->insert('core_module', [
            'name' => 'ghost',
            'version' => '0.9.0',
            'state' => 'installed',
            'installed_at' => '2026-07-29 00:00:00',
        ]);

        $overview = $manager->overview();

        self::assertCount(3, $overview);

        [$fixture, $bare, $ghost] = $overview;

        self::assertSame(['fixture', '1.0.0', '1.0.0', [self::COMPANY_A]], [
            $fixture->name, $fixture->declaredVersion, $fixture->installedVersion, $fixture->enabledCompanyIds,
        ]);
        self::assertSame(['bare', '1.0.0', null, []], [
            $bare->name, $bare->declaredVersion, $bare->installedVersion, $bare->enabledCompanyIds,
        ]);
        self::assertSame(['ghost', null, '0.9.0', []], [
            $ghost->name, $ghost->declaredVersion, $ghost->installedVersion, $ghost->enabledCompanyIds,
        ]);
    }

    private function manager(Module ...$modules): ModuleManager
    {
        $migrations = new MigrationRegistry();
        $migrations->add(IntegrationModule::MIGRATION_NAMESPACE, __DIR__ . '/Fixtures/ModuleFixture/Migrations');

        $connection = $this->dbal;

        return new ModuleManager(
            new ModuleRegistry(array_values($modules)),
            new MigrationRunner(new MigrationFactory($migrations), $migrations, static fn(): Connection => $connection),
            static fn(): Connection => $connection,
        );
    }
}
