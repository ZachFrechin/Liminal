<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Lib\Database\Scope\CompanyIdentity;
use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The identity columns land through the tree's first lib upgrade migration,
 * and the dedicated reader serves them back — nulls included, because
 * identity arrives later, on the company screen, and a fresh install must
 * be complete without it.
 */
#[CoversNothing]
final class CompanyIdentityTest extends IntegrationTestCase
{
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping company identity test.');
        }

        $this->bootstrapInstance($dsn);
    }

    protected function tearDown(): void
    {
        $this->restoreDsn();
    }

    public function testTheUpgradeMigrationShipsTheColumnsNullable(): void
    {
        $columns = $this->dbal->createSchemaManager()->listTableColumns('core_company');

        foreach (['address', 'zip', 'town', 'country_code', 'vat_number', 'registration', 'legal_mentions'] as $name) {
            self::assertArrayHasKey($name, $columns, $name);
            self::assertFalse($columns[$name]->getNotnull(), $name . ' must be nullable');
        }
    }

    public function testTheReaderRoundTripsTheIdentity(): void
    {
        $reader = new CompanyIdentity(fn() => $this->dbal);

        // A fresh company has a name and nothing else — content degrades.
        $bare = $reader->identityOf(1);
        self::assertNotNull($bare);
        self::assertSame('MAIN', $bare['code']);
        self::assertNull($bare['address']);
        self::assertNull($bare['legal_mentions']);

        $this->dbal->update('core_company', [
            'address' => '12 rue des Lilas',
            'zip' => '75011',
            'town' => 'Paris',
            'country_code' => 'FR',
            'vat_number' => 'FR12345678901',
            'registration' => 'RCS Paris 123 456 789',
            'legal_mentions' => 'Late penalty: 3x legal rate. Recovery indemnity: 40 EUR.',
        ], ['id' => 1]);

        $identity = $reader->identityOf(1);
        self::assertNotNull($identity);
        self::assertSame('12 rue des Lilas', $identity['address']);
        self::assertSame('FR', $identity['country_code']);
        self::assertSame('FR12345678901', $identity['vat_number']);
        self::assertStringContainsString('Recovery indemnity', (string) $identity['legal_mentions']);

        // An unknown company is null, never an empty shell.
        self::assertNull($reader->identityOf(999));
    }
}
