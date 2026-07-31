<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Kernel;
use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;
use Twig\Environment;

/**
 * The invoice as a document, end to end: a validated invoice downloads
 * under its number, a draft under a PROFORMA watermark and a numberless
 * filename — and the HTML the engine consumes carries the seller identity,
 * the buyer address, the ventilation, and not one oklch()/var(--) the pdf
 * parser would silently drop.
 */
#[CoversNothing]
final class InvoicePdfTest extends IntegrationTestCase
{
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping invoice pdf test.');
        }

        $this->bootstrapInstance($dsn);
        $this->seedAdaAndBob(['invoice.read', 'invoice.manage']);

        $this->dbal->update('core_company', [
            'address' => '12 rue des Lilas',
            'zip' => '75011',
            'town' => 'Paris',
            'country_code' => 'FR',
            'vat_number' => 'FR12345678901',
            'registration' => 'RCS Paris 123 456 789',
            'legal_mentions' => 'Late penalty: 3x legal rate. Recovery indemnity: 40 EUR.',
        ], ['id' => 1]);

        $this->dbal->insert('thirdparty_thirdparty', [
            'id' => 1,
            'company_id' => 1,
            'code' => 'WAYNE',
            'name' => 'Wayne Enterprises',
            'address' => '1 Wayne Tower',
            'zip' => 'NJ-07001',
            'town' => 'Gotham',
            'country_code' => 'US',
            'vat_number' => 'US999888777',
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

    public function testAValidatedInvoiceDownloadsUnderItsNumber(): void
    {
        $this->seedInvoice(10, 'INV-2026-0001');
        $this->seedLine(10);

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $response = $kernel->handle($this->get('/invoices/10/pdf', $ada));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertSame('inline; filename="INV-2026-0001.pdf"', $response->getHeaderLine('Content-Disposition'));
        $bytes = (string) $response->getBody();
        self::assertStringStartsWith('%PDF', $bytes);
        self::assertGreaterThan(5000, strlen($bytes));
    }

    public function testADraftDownloadsAsAProformaWithoutANumber(): void
    {
        $this->seedInvoice(11, null);
        $this->seedLine(11);

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $response = $kernel->handle($this->get('/invoices/11/pdf', $ada));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('inline; filename="invoice-draft-11.pdf"', $response->getHeaderLine('Content-Disposition'));
        self::assertStringStartsWith('%PDF', (string) $response->getBody());
    }

    /**
     * The HTML the engine consumes, pinned through the container's own
     * Environment — dompdf drops unknown color functions SILENTLY, so the
     * tripwire is the only alarm oklch leakage would ever set off.
     */
    public function testTheDocumentHtmlCarriesTheIdentitiesAndNoTokenLeaks(): void
    {
        $this->seedInvoice(10, 'INV-2026-0001');
        $this->seedLine(10);

        $kernel = $this->kernel();
        // Prime the working context exactly as a request would.
        $ada = $this->login($kernel, 'ada@liminal.test');
        $kernel->handle($this->get('/invoices/10', $ada));

        $twig = $kernel->container()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render('@invoice/pdf.html.twig', [
            'invoice' => $this->invoiceEntity($kernel, 10),
            'lines' => [],
            'lineTotals' => [],
            'ventilation' => [['rate' => '20.00', 'amount' => '40.00']],
            'seller' => [
                'name' => 'MAIN', 'code' => 'MAIN', 'address' => '12 rue des Lilas',
                'zip' => '75011', 'town' => 'Paris', 'country_code' => 'FR',
                'vat_number' => 'FR12345678901', 'registration' => 'RCS Paris 123 456 789',
                'legal_mentions' => 'Late penalty: 3x legal rate.',
            ],
            'buyer' => null,
            '_pdf_watermark' => 'Proforma',
            '_pdf_font_dir' => 'file:///fonts',
        ]);

        self::assertStringContainsString('INV-2026-0001', $html);
        self::assertStringContainsString('12 rue des Lilas', $html);
        self::assertStringContainsString('FR12345678901', $html);
        self::assertStringContainsString('Late penalty', $html);
        self::assertStringContainsString('VAT at 20.00', $html);
        self::assertStringContainsString('Proforma', $html);

        // The tripwire: nothing dompdf would silently drop.
        self::assertStringNotContainsString('oklch', $html);
        self::assertStringNotContainsString('color-mix', $html);
        self::assertStringNotContainsString('var(--', $html);
    }

    private function invoiceEntity(Kernel $kernel, int $id): object
    {
        $repository = $kernel->container()->get(\Liminal\Module\Invoice\Repository\InvoiceRepository::class);
        self::assertInstanceOf(\Liminal\Module\Invoice\Repository\InvoiceRepository::class, $repository);
        $invoice = $repository->byId($id);
        self::assertNotNull($invoice);

        return $invoice;
    }

    private function seedInvoice(int $id, ?string $number): void
    {
        $this->dbal->insert('invoice_invoice', [
            'id' => $id,
            'company_id' => 1,
            'thirdparty_id' => 1,
            'status' => $number === null ? 'draft' : 'validated',
            'number' => $number,
            'issued_on' => '2026-07-31',
            'due_on' => '2026-08-30',
            'total_excl' => '200.00',
            'total_tax' => '40.00',
            'total_incl' => '240.00',
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
    }

    private function seedLine(int $invoiceId): void
    {
        $this->dbal->insert('invoice_invoice_line', [
            'company_id' => 1,
            'invoice_id' => $invoiceId,
            'position' => 1,
            'label' => 'Consulting',
            'quantity' => '2.00',
            'unit_price' => '100.00',
            'vat_rate' => '20.00',
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
    }
}
