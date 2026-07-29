<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Liminal\Lib\Database\DatabaseContributor;
use Liminal\Lib\Database\Exception\SettingsException;
use Liminal\Lib\Database\Migration\MigrationFactory;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Database\Settings\SettingsService;
use Liminal\Registry\Exception\UndeclaredSettingException;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\SettingDefinition;
use Liminal\Registry\SettingScope;
use Liminal\Registry\SettingsRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The settings VALUE layer against real MariaDB: declaration-driven scopes,
 * company-beats-global reads, race-free upserts through the generated
 * sentinel columns.
 */
#[CoversNothing]
final class SettingsServiceTest extends IntegrationTestCase
{
    private const COMPANY_A = 1;
    private const COMPANY_B = 2;

    private Connection $dbal;

    private SettingsRegistry $declarations;

    protected function setUp(): void
    {
        $this->dbal = $this->connection();

        foreach (['core_session', 'core_setting', 'core_module_company', 'core_module', 'core_company', 'core_migration_version'] as $table) {
            $this->dbal->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }

        $migrations = new MigrationRegistry();
        $migrations->add(DatabaseContributor::MIGRATION_NAMESPACE, dirname(__DIR__, 2) . '/libs/Database/Migrations');

        $connection = $this->dbal;
        new MigrationRunner(new MigrationFactory($migrations), $migrations, static fn(): Connection => $connection)
            ->migrateToLatest();

        foreach ([['MAIN', self::COMPANY_A], ['SECOND', self::COMPANY_B]] as [$code, $id]) {
            $this->dbal->insert('core_company', [
                'id' => $id,
                'code' => $code,
                'name' => $code,
                'created_at' => '2026-07-29 00:00:00',
                'updated_at' => '2026-07-29 00:00:00',
            ]);
        }

        $this->declarations = new SettingsRegistry();
        $this->declarations->add(new SettingDefinition('app.locale', SettingScope::Global, 'en_US'));
        $this->declarations->add(new SettingDefinition('app.page_size', SettingScope::Global, 25));
        $this->declarations->add(new SettingDefinition('invoice.prefix', SettingScope::Company, 'INV'));
        $this->declarations->add(new SettingDefinition('ui.theme', SettingScope::User, 'light'));
    }

    public function testTheDeclaredDefaultAnswersWhenNothingIsStored(): void
    {
        self::assertSame('en_US', $this->service(self::COMPANY_A)->get('app.locale'));
        self::assertSame('INV', $this->service(self::COMPANY_A)->get('invoice.prefix'));
    }

    public function testValuesRoundTripWithTheirTypes(): void
    {
        $service = $this->service(self::COMPANY_A);

        $service->set('app.locale', 'fr_FR');
        $service->set('app.page_size', 50);

        self::assertSame('fr_FR', $service->get('app.locale'));
        self::assertSame(50, $service->get('app.page_size'));
    }

    public function testAStoredNullIsAValueNotAnAbsence(): void
    {
        $service = $this->service(self::COMPANY_A);
        $service->set('app.locale', null);

        self::assertNull($service->get('app.locale'));
    }

    public function testArraysRoundTrip(): void
    {
        $service = $this->service(self::COMPANY_A);
        $service->set('app.locale', ['fr_FR', 'en_US']);

        self::assertSame(['fr_FR', 'en_US'], $service->get('app.locale'));
    }

    public function testCompanyValuesAreFencedPerCompany(): void
    {
        $this->service(self::COMPANY_A)->set('invoice.prefix', 'FAC');

        self::assertSame('FAC', $this->service(self::COMPANY_A)->get('invoice.prefix'));
        self::assertSame('INV', $this->service(self::COMPANY_B)->get('invoice.prefix'));
    }

    /**
     * An installation-wide row (seeded by an installer or an admin) serves as
     * the fallback under a company-scoped key: the company's own row beats it,
     * everyone else inherits it.
     */
    public function testACompanyRowBeatsAGlobalFallbackRow(): void
    {
        $this->dbal->insert('core_setting', [
            'setting_key' => 'invoice.prefix',
            'value' => json_encode('GLOB', JSON_THROW_ON_ERROR),
            'scope' => 'global',
            'company_id' => null,
            'user_id' => null,
            'created_at' => '2026-07-29 00:00:00',
            'updated_at' => '2026-07-29 00:00:00',
        ]);

        $this->service(self::COMPANY_A)->set('invoice.prefix', 'FAC');

        self::assertSame('FAC', $this->service(self::COMPANY_A)->get('invoice.prefix'));
        self::assertSame('GLOB', $this->service(self::COMPANY_B)->get('invoice.prefix'));
    }

    public function testTheUpsertOverwritesInsteadOfDuplicating(): void
    {
        $service = $this->service(self::COMPANY_A);

        $service->set('app.locale', 'fr_FR');
        $service->set('app.locale', 'de_DE');

        self::assertSame('de_DE', $service->get('app.locale'));
        self::assertEquals(
            1,
            $this->dbal->fetchOne('SELECT COUNT(*) FROM core_setting WHERE setting_key = ?', ['app.locale']),
        );
    }

    public function testAnUndeclaredKeyIsRefused(): void
    {
        $this->expectException(UndeclaredSettingException::class);

        $this->service(self::COMPANY_A)->get('never.declared');
    }

    public function testUserScopedKeysAreRefusedUntilAuthenticationExists(): void
    {
        $this->expectException(SettingsException::class);

        $this->service(self::COMPANY_A)->get('ui.theme');
    }

    private function service(int $companyId): SettingsService
    {
        return new SettingsService($this->dbal, $this->declarations, new CompanyContext($companyId));
    }
}
