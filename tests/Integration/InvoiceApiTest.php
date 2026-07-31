<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\ApiJourney;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The invoice api surface — the gates and envelopes are pinned once in
 * ThirdpartyApiTest; here only what differs: the money-as-strings payload,
 * the lines in the detail, and the fence on this module's rows.
 */
#[CoversNothing]
final class InvoiceApiTest extends IntegrationTestCase
{
    use ApiJourney;
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping invoice api test.');
        }

        $this->bootstrapInstance($dsn);
        $this->seedAdaAndBob(['invoice.read']);

        $this->dbal->insert('thirdparty_thirdparty', [
            'id' => 1,
            'company_id' => 1,
            'code' => 'WAYNE',
            'name' => 'Wayne Enterprises',
            'is_customer' => 1,
            'is_supplier' => 0,
            'is_active' => 1,
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreDsn();
    }

    public function testTheListCarriesMoneyAsStringsAndTheDetailItsLines(): void
    {
        $this->seedInvoice(10, 1, null, '292.75');
        $this->dbal->insert('invoice_invoice_line', [
            'company_id' => 1,
            'invoice_id' => 10,
            'position' => 1,
            'label' => 'Consulting',
            'quantity' => '2.00',
            'unit_price' => '100.00',
            'vat_rate' => '20.00',
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);

        $kernel = $this->kernel();
        $ada = $this->mintToken('ada@liminal.test');

        $list = $this->jsonFrom($kernel->handle($this->apiGet('/api/v1/invoices', bearer: $ada)));
        self::assertIsArray($list['data']);
        self::assertIsArray($list['data'][0]);
        self::assertSame('draft', $list['data'][0]['status']);
        self::assertSame('292.75', $list['data'][0]['total_incl']);
        self::assertNull($list['data'][0]['number']);

        $detail = $this->jsonFrom($kernel->handle($this->apiGet('/api/v1/invoices/10', bearer: $ada)));
        self::assertIsArray($detail['data']);
        self::assertSame('2026-07-31', $detail['data']['issued_on']);
        self::assertIsArray($detail['data']['lines']);
        self::assertCount(1, $detail['data']['lines']);
        self::assertSame(
            ['id' => 1, 'position' => 1, 'label' => 'Consulting', 'quantity' => '2.00', 'unit_price' => '100.00', 'vat_rate' => '20.00'],
            $detail['data']['lines'][0],
        );
    }

    public function testAForeignCompanysInvoiceAnswers404(): void
    {
        $this->seedSecondCompany(granting: null);
        $this->dbal->insert('thirdparty_thirdparty', [
            'id' => 2,
            'company_id' => 2,
            'code' => 'LEX',
            'name' => 'LexCorp',
            'is_customer' => 1,
            'is_supplier' => 0,
            'is_active' => 1,
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
        $this->dbal->insert('invoice_invoice', [
            'id' => 20,
            'company_id' => 2,
            'thirdparty_id' => 2,
            'status' => 'draft',
            'issued_on' => '2026-07-31',
            'total_excl' => '0.00',
            'total_tax' => '0.00',
            'total_incl' => '0.00',
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);

        $kernel = $this->kernel();
        $ada = $this->mintToken('ada@liminal.test');

        self::assertSame(404, $kernel->handle($this->apiGet('/api/v1/invoices/20', bearer: $ada))->getStatusCode());
    }

    private function seedInvoice(int $id, int $thirdpartyId, ?string $number, string $totalIncl): void
    {
        $this->dbal->insert('invoice_invoice', [
            'id' => $id,
            'company_id' => 1,
            'thirdparty_id' => $thirdpartyId,
            'status' => $number === null ? 'draft' : 'validated',
            'number' => $number,
            'issued_on' => '2026-07-31',
            'total_excl' => '250.00',
            'total_tax' => '42.75',
            'total_incl' => $totalIncl,
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
    }
}
