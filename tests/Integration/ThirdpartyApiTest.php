<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\ApiJourney;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The first module api surface, pinned in full: token and permission gates,
 * the {data, meta} envelope, the company narrowing (per row AND per header),
 * and the module gate answering the same 404 as the screens. The invoice
 * and order suites lean on these pins and test only what differs.
 */
#[CoversNothing]
final class ThirdpartyApiTest extends IntegrationTestCase
{
    use ApiJourney;
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping thirdparty api test.');
        }

        $this->bootstrapInstance($dsn);
        $this->seedAdaAndBob(['thirdparty.read']);
    }

    protected function tearDown(): void
    {
        $this->restoreDsn();
    }

    public function testTheCollectionRequiresATokenAndThePermission(): void
    {
        $kernel = $this->kernel();

        self::assertSame(401, $kernel->handle($this->apiGet('/api/v1/thirdparties'))->getStatusCode());

        // Bob authenticates fine — and holds nothing: 403, not 401.
        $bob = $this->mintToken('bob@liminal.test');
        $forbidden = $kernel->handle($this->apiGet('/api/v1/thirdparties', bearer: $bob));
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertStringContainsString('"error"', (string) $forbidden->getBody());
    }

    public function testTheCollectionCarriesDataMetaAndTheCompanyFence(): void
    {
        $this->seedThirdparty(1, 1, 'WAYNE', 'Wayne Enterprises');
        $this->seedThirdparty(2, 1, 'STARK', 'Stark Industries');
        $this->seedSecondCompany(granting: null);
        $this->seedThirdparty(3, 2, 'LEX', 'LexCorp');

        $kernel = $this->kernel();
        $ada = $this->mintToken('ada@liminal.test');

        $body = $this->jsonFrom($kernel->handle($this->apiGet('/api/v1/thirdparties', bearer: $ada)));

        self::assertSame(['page' => 1, 'pages' => 1, 'total' => 2, 'perPage' => 25], $body['meta'] ?? null);
        self::assertIsArray($body['data']);
        self::assertCount(2, $body['data']);
        $names = array_column($body['data'], 'name');
        self::assertContains('Wayne Enterprises', $names);
        self::assertNotContains('LexCorp', $names);

        // The payload shape, pinned once on the first row.
        $first = $body['data'][0];
        self::assertIsArray($first);
        self::assertSame(
            ['id', 'code', 'name', 'alias', 'customer', 'supplier', 'active', 'email', 'phone',
                'address', 'zip', 'town', 'country_code', 'vat_number', 'notes', 'created_at', 'updated_at'],
            array_keys($first),
        );
        self::assertIsBool($first['customer']);

        // Search reaches through the same repository escape rules.
        $wayne = $this->jsonFrom($kernel->handle($this->apiGet('/api/v1/thirdparties?q=Wayne', bearer: $ada)));
        self::assertIsArray($wayne['meta']);
        self::assertSame(1, $wayne['meta']['total']);
    }

    public function testTheDetailNarrowsToTheWorkingCompany(): void
    {
        $this->seedThirdparty(1, 1, 'WAYNE', 'Wayne Enterprises');
        $this->seedSecondCompany(granting: null);
        $this->seedThirdparty(3, 2, 'LEX', 'LexCorp');

        $kernel = $this->kernel();
        $ada = $this->mintToken('ada@liminal.test');

        $detail = $this->jsonFrom($kernel->handle($this->apiGet('/api/v1/thirdparties/1', bearer: $ada)));
        self::assertIsArray($detail['data']);
        self::assertSame('WAYNE', $detail['data']['code']);

        // A foreign company's row answers the same 404 as a missing one.
        self::assertSame(404, $kernel->handle($this->apiGet('/api/v1/thirdparties/3', bearer: $ada))->getStatusCode());
    }

    public function testTheCompanyHeaderSwitchesTheWorkingSet(): void
    {
        $this->seedThirdparty(1, 1, 'WAYNE', 'Wayne Enterprises');
        $this->seedSecondCompany(granting: 'ada@liminal.test');
        $this->seedThirdparty(3, 2, 'LEX', 'LexCorp');

        $kernel = $this->kernel();
        $ada = $this->mintToken('ada@liminal.test');

        $acme = $this->jsonFrom($kernel->handle($this->apiGet('/api/v1/thirdparties', bearer: $ada, company: '2')));
        self::assertIsArray($acme['data']);
        self::assertSame(['LexCorp'], array_column($acme['data'], 'name'));
    }

    public function testADisabledModuleAnswersTheSame404AsTheScreens(): void
    {
        $kernel = $this->kernel();
        $ada = $this->mintToken('ada@liminal.test');

        $this->dbal->executeStatement(
            "UPDATE core_module_company SET enabled = 0
             WHERE company_id = 1 AND module_id = (SELECT id FROM core_module WHERE name = 'thirdparty')",
        );

        $response = $kernel->handle($this->apiGet('/api/v1/thirdparties', bearer: $ada));
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('"error"', (string) $response->getBody());
    }

    private function seedThirdparty(int $id, int $companyId, string $code, string $name): void
    {
        $this->dbal->insert('thirdparty_thirdparty', [
            'id' => $id,
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'is_customer' => 1,
            'is_supplier' => 0,
            'is_active' => 1,
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
    }
}
