<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The invoice screens through a browser: gating, the list with its party
 * column and status badges, the detail with lines and the ventilated totals
 * — and the working-company line an invoice can never cross.
 */
#[CoversNothing]
final class InvoiceHttpTest extends IntegrationTestCase
{
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping invoice http test.');
        }

        $this->bootstrapInstance($dsn);
        $this->seedAdaAndBob(['invoice.read', 'invoice.manage']);
    }

    protected function tearDown(): void
    {
        $this->restoreDsn();
    }

    public function testTheMenuAndScreensRenderTranslatedAndGated(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $account = (string) $kernel->handle($this->get('/account', $ada))->getBody();

        self::assertStringContainsString('>Invoices</a>', $account);
        self::assertStringNotContainsString('invoice.menu.', $account);

        $list = (string) $kernel->handle($this->get('/invoices', $ada))->getBody();

        self::assertStringContainsString('No invoice here yet.', $list);
        self::assertStringNotContainsString('invoice.list.', $list);

        // Bob holds nothing: no menu entry, no page.
        $bob = $this->login($kernel, 'bob@liminal.test');
        self::assertStringNotContainsString('href="/invoices"', (string) $kernel->handle($this->get('/account', $bob))->getBody());
        self::assertSame(403, $kernel->handle($this->get('/invoices', $bob))->getStatusCode());
    }

    public function testTheListCarriesPartyStatusAndTotals(): void
    {
        $this->seedThirdparty(1, 1, 'Wayne Enterprises');
        $this->seedInvoice(10, 1, 1, null, '120.00');
        $this->seedInvoice(11, 1, 1, 'INV-2026-0001', '240.00');

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $list = (string) $kernel->handle($this->get('/invoices', $ada))->getBody();

        self::assertStringContainsString('Wayne Enterprises', $list);
        self::assertStringContainsString('INV-2026-0001', $list);
        self::assertStringContainsString('>Draft</span>', $list);
        self::assertStringContainsString('>Validated</span>', $list);
        self::assertStringContainsString('240.00', $list);

        // Search by the party's name reaches through the join.
        $wayne = (string) $kernel->handle($this->get('/invoices?q=Wayne', $ada))->getBody();
        self::assertStringContainsString('INV-2026-0001', $wayne);

        $none = (string) $kernel->handle($this->get('/invoices?q=Stark', $ada))->getBody();
        self::assertStringContainsString('No invoice here yet.', $none);
    }

    public function testTheDetailShowsLinesAndVentilatedTotals(): void
    {
        $this->seedThirdparty(1, 1, 'Wayne Enterprises');
        $this->seedInvoice(10, 1, 1, null, '0.00');
        foreach ([[1, 'Consulting', '2.00', '100.00', '20.00'], [2, 'Books', '1.00', '50.00', '5.50']] as [$position, $label, $qty, $price, $rate]) {
            $this->dbal->insert('invoice_invoice_line', [
                'company_id' => 1,
                'invoice_id' => 10,
                'position' => $position,
                'label' => $label,
                'quantity' => $qty,
                'unit_price' => $price,
                'vat_rate' => $rate,
                'created_at' => '2026-07-31 00:00:00',
                'updated_at' => '2026-07-31 00:00:00',
            ]);
        }

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $detail = (string) $kernel->handle($this->get('/invoices/10', $ada))->getBody();

        self::assertStringContainsString('Consulting', $detail);
        self::assertStringContainsString('Books', $detail);
        // The ventilation, rate by rate, through the hook-wrapped service:
        // 200.00 at 20% → 40.00, 50.00 at 5.5% → 2.75.
        self::assertStringContainsString('VAT at 5.50', $detail);
        self::assertStringContainsString('2.75', $detail);
        self::assertStringContainsString('VAT at 20.00', $detail);
        self::assertStringContainsString('40.00', $detail);
        self::assertStringNotContainsString('invoice.detail.', $detail);
    }

    public function testAnotherCompanysInvoiceAnswers404(): void
    {
        $this->seedSecondCompany(granting: 'ada@liminal.test');
        $this->seedThirdparty(1, 2, 'Stark Industries');
        $this->seedInvoice(20, 2, 1, null, '0.00');

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        // ACME is accessible to Ada — but MAIN is the working company.
        self::assertSame(404, $kernel->handle($this->get('/invoices/20', $ada))->getStatusCode());
    }

    public function testADraftIsBornGrowsLinesAndItsTotalsFollow(): void
    {
        $this->seedThirdparty(1, 1, 'Wayne Enterprises');

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        // Create the draft from the form.
        $form = $kernel->handle($this->get('/invoices/create', $ada));
        $created = $kernel->handle($this->post(
            '/invoices/create',
            [
                'thirdparty_id' => '1',
                'issued_on' => '2026-07-31',
                'due_on' => '2026-08-30',
                '_token' => $this->tokenFrom((string) $form->getBody()),
            ],
            $ada,
        ));

        self::assertSame(302, $created->getStatusCode());
        $location = $created->getHeaderLine('Location');
        self::assertMatchesRegularExpression('~^/invoices/\d+$~', $location);

        $detail = (string) $kernel->handle($this->get($location, $ada))->getBody();
        self::assertStringContainsString('Draft invoice created.', $detail);
        $token = $this->tokenFrom($detail);

        // Two lines, two rates — the totals and their ventilation follow.
        foreach ([['Consulting', '2', '100.00', '20.00'], ['Books', '1', '50,00', '5,50']] as [$label, $qty, $price, $rate]) {
            $added = $kernel->handle($this->post(
                $location . '/lines',
                ['label' => $label, 'quantity' => $qty, 'unit_price' => $price, 'vat_rate' => $rate, '_token' => $token],
                $ada,
            ));
            self::assertSame(302, $added->getStatusCode());
        }

        $withLines = (string) $kernel->handle($this->get($location, $ada))->getBody();
        self::assertStringContainsString('Consulting', $withLines);
        self::assertStringContainsString('250.00', $withLines);
        self::assertStringContainsString('292.75', $withLines);

        // The stored columns moved with the lines — recomputed == stored.
        self::assertEquals('250.00', $this->dbal->fetchOne('SELECT total_excl FROM invoice_invoice'));
        self::assertEquals('42.75', $this->dbal->fetchOne('SELECT total_tax FROM invoice_invoice'));
        self::assertEquals('292.75', $this->dbal->fetchOne('SELECT total_incl FROM invoice_invoice'));

        // Remove a line: the totals shrink with it.
        $lineId = $this->dbal->fetchOne("SELECT id FROM invoice_invoice_line WHERE label = 'Books'");
        self::assertIsNumeric($lineId);

        $removed = $kernel->handle($this->post(
            $location . '/lines/' . (int) $lineId . '/remove',
            ['_token' => $token],
            $ada,
        ));
        self::assertSame(302, $removed->getStatusCode());
        self::assertEquals('240.00', $this->dbal->fetchOne('SELECT total_incl FROM invoice_invoice'));

        // Edit the header dates.
        $updated = $kernel->handle($this->post(
            $location,
            ['thirdparty_id' => '1', 'issued_on' => '2026-08-01', 'due_on' => '', '_token' => $token],
            $ada,
        ));
        self::assertSame(302, $updated->getStatusCode());
        self::assertEquals('2026-08-01', $this->dbal->fetchOne('SELECT issued_on FROM invoice_invoice'));

        // Delete the draft: lines ride the cascade.
        $deleted = $kernel->handle($this->post($location . '/delete', ['_token' => $token], $ada));
        self::assertSame('/invoices', $deleted->getHeaderLine('Location'));
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM invoice_invoice'));
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM invoice_invoice_line'));
    }

    public function testRefusalsFlashInsteadOfThrowing(): void
    {
        $this->seedThirdparty(1, 1, 'Wayne Enterprises');
        $this->seedSecondCompany(granting: null);
        $this->seedThirdparty(2, 2, 'Stark Industries');

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $form = $kernel->handle($this->get('/invoices/create', $ada));
        $token = $this->tokenFrom((string) $form->getBody());

        // Another company's thirdparty does not exist here.
        $foreign = $kernel->handle($this->post(
            '/invoices/create',
            ['thirdparty_id' => '2', 'issued_on' => '2026-07-31', '_token' => $token],
            $ada,
        ));
        self::assertSame('/invoices/create', $foreign->getHeaderLine('Location'));

        $reloaded = (string) $kernel->handle($this->get('/invoices/create', $ada))->getBody();
        self::assertStringContainsString('does not exist in this company', $reloaded);

        // An overflowing quantity is a flash, not fabricated cents.
        $this->seedInvoice(10, 1, 1, null, '0.00');
        $bad = $kernel->handle($this->post(
            '/invoices/10/lines',
            ['label' => 'Huge', 'quantity' => '9999999999', 'unit_price' => '1.00', 'vat_rate' => '20.00', '_token' => $token],
            $ada,
        ));
        self::assertSame('/invoices/10', $bad->getHeaderLine('Location'));
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM invoice_invoice_line'));
    }

    private function seedThirdparty(int $id, int $companyId, string $name): void
    {
        $this->dbal->insert('thirdparty_thirdparty', [
            'id' => $id,
            'company_id' => $companyId,
            'code' => 'TP-' . $id . '-' . $companyId,
            'name' => $name,
            'is_customer' => 1,
            'is_supplier' => 0,
            'is_active' => 1,
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
    }

    private function seedInvoice(int $id, int $companyId, int $thirdpartyId, ?string $number, string $totalIncl): void
    {
        $this->dbal->insert('invoice_invoice', [
            'id' => $id,
            'company_id' => $companyId,
            'thirdparty_id' => $thirdpartyId,
            'status' => $number === null ? 'draft' : 'validated',
            'number' => $number,
            'issued_on' => '2026-07-31',
            'total_excl' => '0.00',
            'total_tax' => '0.00',
            'total_incl' => $totalIncl,
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
    }
}
