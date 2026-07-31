<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\ApiJourney;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The order api surface — only what the third state adds beyond the pinned
 * pattern: the conversion pointer travelling in the payload, on the same
 * fence as everything else.
 */
#[CoversNothing]
final class OrderApiTest extends IntegrationTestCase
{
    use ApiJourney;
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping order api test.');
        }

        $this->bootstrapInstance($dsn);
        $this->seedAdaAndBob(['order.read']);

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

    public function testAnInvoicedOrderCarriesItsPointerAndItsLines(): void
    {
        $this->dbal->insert('invoice_invoice', [
            'id' => 50,
            'company_id' => 1,
            'thirdparty_id' => 1,
            'status' => 'validated',
            'number' => 'INV-2026-0001',
            'issued_on' => '2026-07-31',
            'total_excl' => '0.00',
            'total_tax' => '0.00',
            'total_incl' => '0.00',
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
        $this->dbal->insert('order_order', [
            'id' => 10,
            'company_id' => 1,
            'thirdparty_id' => 1,
            'status' => 'invoiced',
            'number' => 'CMD-2026-0001',
            'issued_on' => '2026-07-31',
            'wanted_on' => '2026-08-30',
            'invoice_id' => 50,
            'invoiced_at' => '2026-07-31 10:00:00',
            'total_excl' => '250.00',
            'total_tax' => '42.75',
            'total_incl' => '292.75',
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
        $this->dbal->insert('order_order_line', [
            'company_id' => 1,
            'order_id' => 10,
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

        $list = $this->jsonFrom($kernel->handle($this->apiGet('/api/v1/orders', bearer: $ada)));
        self::assertIsArray($list['data']);
        self::assertIsArray($list['data'][0]);
        self::assertSame('invoiced', $list['data'][0]['status']);
        self::assertSame(50, $list['data'][0]['invoice_id']);
        self::assertSame('2026-08-30', $list['data'][0]['wanted_on']);

        $detail = $this->jsonFrom($kernel->handle($this->apiGet('/api/v1/orders/10', bearer: $ada)));
        self::assertIsArray($detail['data']);
        self::assertSame('CMD-2026-0001', $detail['data']['number']);
        self::assertIsString($detail['data']['invoiced_at']);
        self::assertStringStartsWith('2026-07-31T10:00:00', $detail['data']['invoiced_at']);
        self::assertIsArray($detail['data']['lines']);
        self::assertCount(1, $detail['data']['lines']);

        // The permission is order.read: the invoice pointer travels as an id,
        // never as embedded invoice data another permission protects.
        self::assertArrayNotHasKey('invoice', $detail['data']);
    }
}
