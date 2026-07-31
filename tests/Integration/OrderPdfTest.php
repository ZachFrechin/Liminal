<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The order pdf — the invoice pins carry the pattern; here only what the
 * order adds: two numbered printable states, and the proforma face on a
 * draft.
 */
#[CoversNothing]
final class OrderPdfTest extends IntegrationTestCase
{
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping order pdf test.');
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

    public function testEveryStatePrintsUnderItsOwnFilename(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        // Validated: the definitive number is the filename.
        $this->seedOrder(10, 'CMD-2026-0001', 'validated');
        $validated = $kernel->handle($this->get('/orders/10/pdf', $ada));
        self::assertSame(200, $validated->getStatusCode());
        self::assertSame('application/pdf', $validated->getHeaderLine('Content-Type'));
        self::assertSame('inline; filename="CMD-2026-0001.pdf"', $validated->getHeaderLine('Content-Disposition'));
        self::assertStringStartsWith('%PDF', (string) $validated->getBody());

        // Invoiced: still a confirmed order, still its number.
        $this->dbal->insert('invoice_invoice', [
            'id' => 50, 'company_id' => 1, 'thirdparty_id' => 1, 'status' => 'validated',
            'number' => 'INV-2026-0001', 'issued_on' => '2026-07-31',
            'total_excl' => '0.00', 'total_tax' => '0.00', 'total_incl' => '0.00',
            'created_at' => '2026-07-31 00:00:00', 'updated_at' => '2026-07-31 00:00:00',
        ]);
        $this->dbal->update('order_order', [
            'status' => 'invoiced', 'invoice_id' => 50, 'invoiced_at' => '2026-07-31 10:00:00',
        ], ['id' => 10]);
        $invoiced = $kernel->handle($this->get('/orders/10/pdf', $ada));
        self::assertSame('inline; filename="CMD-2026-0001.pdf"', $invoiced->getHeaderLine('Content-Disposition'));

        // Draft: proforma face, numberless filename.
        $this->seedOrder(11, null, 'draft');
        $draft = $kernel->handle($this->get('/orders/11/pdf', $ada));
        self::assertSame('inline; filename="order-draft-11.pdf"', $draft->getHeaderLine('Content-Disposition'));
        self::assertStringStartsWith('%PDF', (string) $draft->getBody());
    }

    private function seedOrder(int $id, ?string $number, string $status): void
    {
        $this->dbal->insert('order_order', [
            'id' => $id,
            'company_id' => 1,
            'thirdparty_id' => 1,
            'status' => $status,
            'number' => $number,
            'issued_on' => '2026-07-31',
            'total_excl' => '100.00',
            'total_tax' => '20.00',
            'total_incl' => '120.00',
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
        $this->dbal->insert('order_order_line', [
            'company_id' => 1,
            'order_id' => $id,
            'position' => 1,
            'label' => 'Consulting',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'vat_rate' => '20.00',
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
    }
}
