<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The conversion, end to end: a validated order becomes a draft invoice in
 * one commit — lines copied, totals recomputed by the INVOICE's own hook,
 * the order's pointer set — then every escape hatch answers politely: the
 * born invoice refuses deletion (it realises an order), the party refuses
 * deletion (two modules answer the same veto), re-conversion refuses (the
 * door opened once), and the born invoice still earns its own INV number.
 */
#[CoversNothing]
final class OrderConversionTest extends IntegrationTestCase
{
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping order conversion test.');
        }

        $this->bootstrapInstance($dsn);
        $this->seedAdaAndBob([
            'order.read', 'order.manage',
            'invoice.read', 'invoice.manage',
            'thirdparty.read', 'thirdparty.manage',
        ]);

        $this->seedThirdparty(1, 'Wayne Enterprises');
    }

    protected function tearDown(): void
    {
        $this->restoreDsn();
    }

    public function testAValidatedOrderBecomesADraftInvoiceOnceAndForever(): void
    {
        $this->seedValidatedOrder(10, 1, 'CMD-2026-0001');

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $detail = (string) $kernel->handle($this->get('/orders/10', $ada))->getBody();
        self::assertStringContainsString('Invoice this order', $detail);
        $token = $this->tokenFrom($detail);

        // The conversion: one POST, one commit, a draft invoice is born.
        $converted = $kernel->handle($this->post('/orders/10/invoice', ['_token' => $token], $ada));
        self::assertSame(302, $converted->getStatusCode());
        $location = $converted->getHeaderLine('Location');
        self::assertMatchesRegularExpression('~^/invoices/\d+$~', $location);
        $invoiceId = (int) substr($location, strlen('/invoices/'));

        // The invoice: a DRAFT of today, same party, the lines copied with
        // their positions, the totals recomputed by ITS own hook.
        self::assertEquals('draft', $this->dbal->fetchOne('SELECT status FROM invoice_invoice WHERE id = ?', [$invoiceId]));
        self::assertEquals(1, $this->dbal->fetchOne('SELECT thirdparty_id FROM invoice_invoice WHERE id = ?', [$invoiceId]));
        self::assertEquals(date('Y-m-d'), $this->dbal->fetchOne('SELECT issued_on FROM invoice_invoice WHERE id = ?', [$invoiceId]));
        self::assertEquals('292.75', $this->dbal->fetchOne('SELECT total_incl FROM invoice_invoice WHERE id = ?', [$invoiceId]));
        self::assertSame(
            [[1, 'Consulting'], [2, 'Books']],
            $this->dbal->fetchAllNumeric('SELECT position, label FROM invoice_invoice_line WHERE invoice_id = ? ORDER BY position', [$invoiceId]),
        );

        // The order: invoiced, pointing at the invoice, timestamped.
        self::assertEquals('invoiced', $this->dbal->fetchOne('SELECT status FROM order_order WHERE id = 10'));
        self::assertEquals($invoiceId, $this->dbal->fetchOne('SELECT invoice_id FROM order_order WHERE id = 10'));
        self::assertNotNull($this->dbal->fetchOne('SELECT invoiced_at FROM order_order WHERE id = 10'));

        // Both facts hit the audit trail: the transition AND the birth.
        self::assertEquals(1, $this->dbal->fetchOne("SELECT COUNT(*) FROM core_audit_event WHERE event = 'ORDER_INVOICED'"));
        self::assertEquals(1, $this->dbal->fetchOne("SELECT COUNT(*) FROM core_audit_event WHERE event = 'INVOICE_CREATED'"));

        // The order's page turned: Invoiced badge, link to the invoice.
        $after = (string) $kernel->handle($this->get('/orders/10', $ada))->getBody();
        self::assertStringContainsString('badge-invoiced">Invoiced</span>', $after);
        self::assertStringContainsString('href="' . $location . '"', $after);
        self::assertStringNotContainsString('Invoice this order', $after);

        // Re-conversion: the door opened once.
        $again = $kernel->handle($this->post('/orders/10/invoice', ['_token' => $token], $ada));
        self::assertSame('/orders/10', $again->getHeaderLine('Location'));
        $flash = (string) $kernel->handle($this->get('/orders/10', $ada))->getBody();
        self::assertStringContainsString('already been invoiced', $flash);
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM invoice_invoice'));

        // Deleting the born invoice: the veto answers before the RESTRICT
        // belt has to — it realises a validated order.
        $refused = $kernel->handle($this->post($location . '/delete', ['_token' => $token], $ada));
        self::assertSame($location, $refused->getHeaderLine('Location'));
        $invoicePage = (string) $kernel->handle($this->get($location, $ada))->getBody();
        self::assertStringContainsString('realises a validated order', $invoicePage);
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM invoice_invoice'));

        // Deleting the party: the invoice module answers first (module
        // order), the flash shows the first reason of the shared list.
        $party = $kernel->handle($this->post('/thirdparties/1/delete', ['_token' => $token], $ada));
        self::assertSame(302, $party->getStatusCode());
        $partyPage = (string) $kernel->handle($this->get('/thirdparties/1', $ada))->getBody();
        self::assertStringContainsString('carries invoices', $partyPage);
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM thirdparty_thirdparty'));

        // The born invoice validates on its own: INV number, own sequence.
        $invoiceToken = $this->tokenFrom($invoicePage);
        $validated = $kernel->handle($this->post($location . '/validate', ['_token' => $invoiceToken], $ada));
        self::assertSame(302, $validated->getStatusCode());
        self::assertEquals(
            sprintf('INV-%d-0001', (int) date('Y')),
            $this->dbal->fetchOne('SELECT number FROM invoice_invoice WHERE id = ?', [$invoiceId]),
        );
    }

    public function testADraftOrderCannotConvertAndTheOrderVetoAnswersAlone(): void
    {
        // A draft order: not validated, so no conversion — and the ONLY
        // document naming party 2, so the order listener answers the veto
        // alone while the invoice listener stays silent: proof the second
        // responder runs on its own reading of the same dispatch.
        $this->seedThirdparty(2, 'Stark Industries');
        $this->seedDraftOrder(20, 2);

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $detail = (string) $kernel->handle($this->get('/orders/20', $ada))->getBody();
        self::assertStringNotContainsString('Invoice this order', $detail);
        $token = $this->tokenFrom($detail);

        $converted = $kernel->handle($this->post('/orders/20/invoice', ['_token' => $token], $ada));
        self::assertSame('/orders/20', $converted->getHeaderLine('Location'));
        $flash = (string) $kernel->handle($this->get('/orders/20', $ada))->getBody();
        self::assertStringContainsString('Only a validated order', $flash);
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM invoice_invoice'));

        $party = $kernel->handle($this->post('/thirdparties/2/delete', ['_token' => $token], $ada));
        self::assertSame(302, $party->getStatusCode());
        $partyPage = (string) $kernel->handle($this->get('/thirdparties/2', $ada))->getBody();
        self::assertStringContainsString('still has orders', $partyPage);
        self::assertEquals(2, $this->dbal->fetchOne('SELECT COUNT(*) FROM thirdparty_thirdparty'));
    }

    private function seedThirdparty(int $id, string $name): void
    {
        $this->dbal->insert('thirdparty_thirdparty', [
            'id' => $id,
            'company_id' => 1,
            'code' => 'TP-' . $id,
            'name' => $name,
            'is_customer' => 1,
            'is_supplier' => 0,
            'is_active' => 1,
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
    }

    private function seedValidatedOrder(int $id, int $thirdpartyId, string $number): void
    {
        $this->dbal->insert('order_order', [
            'id' => $id,
            'company_id' => 1,
            'thirdparty_id' => $thirdpartyId,
            'status' => 'validated',
            'number' => $number,
            'issued_on' => '2026-07-31',
            'total_excl' => '250.00',
            'total_tax' => '42.75',
            'total_incl' => '292.75',
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
        foreach ([[1, 'Consulting', '2.00', '100.00', '20.00'], [2, 'Books', '1.00', '50.00', '5.50']] as [$position, $label, $qty, $price, $rate]) {
            $this->dbal->insert('order_order_line', [
                'company_id' => 1,
                'order_id' => $id,
                'position' => $position,
                'label' => $label,
                'quantity' => $qty,
                'unit_price' => $price,
                'vat_rate' => $rate,
                'created_at' => '2026-07-31 00:00:00',
                'updated_at' => '2026-07-31 00:00:00',
            ]);
        }
    }

    private function seedDraftOrder(int $id, int $thirdpartyId): void
    {
        $this->dbal->insert('order_order', [
            'id' => $id,
            'company_id' => 1,
            'thirdparty_id' => $thirdpartyId,
            'status' => 'draft',
            'issued_on' => '2026-07-31',
            'total_excl' => '0.00',
            'total_tax' => '0.00',
            'total_incl' => '0.00',
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
    }
}
