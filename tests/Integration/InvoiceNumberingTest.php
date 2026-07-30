<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The gap-free sequence, end to end: consecutive validations mint
 * consecutive numbers, the sequence is per company AND per year (foreign
 * counters never bleed in), and a validated invoice refuses every mutation
 * with a flash — the number is the point of no return.
 */
#[CoversNothing]
final class InvoiceNumberingTest extends IntegrationTestCase
{
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping invoice numbering test.');
        }

        $this->bootstrapInstance($dsn);
        $this->seedAdaAndBob(['invoice.read', 'invoice.manage']);

        $this->dbal->insert('thirdparty_thirdparty', [
            'id' => 1,
            'company_id' => 1,
            'code' => 'TP-1',
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

    public function testConsecutiveValidationsMintConsecutivePerCompanyPerYearNumbers(): void
    {
        $year = (int) date('Y');

        // Foreign counters that must never bleed into this sequence: last
        // year's, and another company's for the same year.
        $this->dbal->insert('invoice_sequence', ['company_id' => 1, 'year' => $year - 1, 'counter' => 41]);
        $this->dbal->executeStatement(
            "INSERT INTO core_company (id, code, name, created_at, updated_at) VALUES (2, 'ACME', 'ACME', NOW(), NOW())",
        );
        $this->dbal->insert('invoice_sequence', ['company_id' => 2, 'year' => $year, 'counter' => 9]);

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $numbers = [];

        foreach ([10, 11] as $id) {
            $this->seedDraftWithLine($id);

            $detail = (string) $kernel->handle($this->get('/invoices/' . $id, $ada))->getBody();
            $validated = $kernel->handle($this->post(
                '/invoices/' . $id . '/validate',
                ['_token' => $this->tokenFrom($detail)],
                $ada,
            ));
            self::assertSame(302, $validated->getStatusCode());

            $numbers[] = $this->dbal->fetchOne('SELECT number FROM invoice_invoice WHERE id = ?', [$id]);
        }

        self::assertSame(
            [sprintf('INV-%d-0001', $year), sprintf('INV-%d-0002', $year)],
            $numbers,
        );

        // The freeze is complete: status, totals, today's issue date.
        self::assertEquals('validated', $this->dbal->fetchOne('SELECT status FROM invoice_invoice WHERE id = 10'));
        self::assertEquals('120.00', $this->dbal->fetchOne('SELECT total_incl FROM invoice_invoice WHERE id = 10'));
        self::assertEquals(date('Y-m-d'), $this->dbal->fetchOne('SELECT issued_on FROM invoice_invoice WHERE id = 10'));

        // The foreign counters did not move.
        self::assertEquals(41, $this->dbal->fetchOne(
            'SELECT counter FROM invoice_sequence WHERE company_id = 1 AND year = ?',
            [$year - 1],
        ));
        self::assertEquals(9, $this->dbal->fetchOne(
            'SELECT counter FROM invoice_sequence WHERE company_id = 2 AND year = ?',
            [$year],
        ));
    }

    public function testAValidatedInvoiceRefusesEveryMutationWithAFlash(): void
    {
        $this->seedDraftWithLine(10);

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $detail = (string) $kernel->handle($this->get('/invoices/10', $ada))->getBody();
        $token = $this->tokenFrom($detail);

        $kernel->handle($this->post('/invoices/10/validate', ['_token' => $token], $ada));

        // Update, line add, delete, and a second validation: all flash+302.
        foreach ([
            ['/invoices/10', ['thirdparty_id' => '1', 'issued_on' => '2026-08-01']],
            ['/invoices/10/lines', ['label' => 'Late', 'quantity' => '1', 'unit_price' => '5.00', 'vat_rate' => '20.00']],
            ['/invoices/10/delete', []],
            ['/invoices/10/validate', []],
        ] as [$path, $body]) {
            $refused = $kernel->handle($this->post($path, [...$body, '_token' => $token], $ada));
            self::assertSame(302, $refused->getStatusCode(), $path);

            $after = (string) $kernel->handle($this->get('/invoices/10', $ada))->getBody();
            self::assertStringContainsString('immutable', $after, $path);
        }

        // Nothing moved: the invoice, its line and its number survived.
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM invoice_invoice'));
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM invoice_invoice_line'));
        self::assertEquals('validated', $this->dbal->fetchOne('SELECT status FROM invoice_invoice'));
    }

    private function seedDraftWithLine(int $id): void
    {
        $this->dbal->insert('invoice_invoice', [
            'id' => $id,
            'company_id' => 1,
            'thirdparty_id' => 1,
            'status' => 'draft',
            'issued_on' => '2026-07-31',
            'total_excl' => '100.00',
            'total_tax' => '20.00',
            'total_incl' => '120.00',
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
        $this->dbal->insert('invoice_invoice_line', [
            'company_id' => 1,
            'invoice_id' => $id,
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
