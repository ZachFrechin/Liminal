<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The CMD sequence, condensed: the machinery is the lib's YearlySequence
 * already proven gap-free on the invoice side — what is pinned here is what
 * is NEW: the order's own table and format, and the two document sequences
 * never mixing even inside one company and one year.
 */
#[CoversNothing]
final class OrderNumberingTest extends IntegrationTestCase
{
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping order numbering test.');
        }

        $this->bootstrapInstance($dsn);
        $this->seedAdaAndBob(['order.read', 'order.manage']);

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

    public function testConsecutiveCmdNumbersNeverTouchTheInvoiceSequence(): void
    {
        $year = (int) date('Y');

        // Same company, same year, other document: the sibling sequence a
        // shared counter would corrupt.
        $this->dbal->insert('invoice_sequence', ['company_id' => 1, 'year' => $year, 'counter' => 7]);

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $numbers = [];

        foreach ([10, 11] as $id) {
            $this->seedDraftWithLine($id);

            $detail = (string) $kernel->handle($this->get('/orders/' . $id, $ada))->getBody();
            $validated = $kernel->handle($this->post(
                '/orders/' . $id . '/validate',
                ['_token' => $this->tokenFrom($detail)],
                $ada,
            ));
            self::assertSame(302, $validated->getStatusCode());

            $numbers[] = $this->dbal->fetchOne('SELECT number FROM order_order WHERE id = ?', [$id]);
        }

        self::assertSame(
            [sprintf('CMD-%d-0001', $year), sprintf('CMD-%d-0002', $year)],
            $numbers,
        );

        // The freeze is complete: status, totals, today's issue date.
        self::assertEquals('validated', $this->dbal->fetchOne('SELECT status FROM order_order WHERE id = 10'));
        self::assertEquals('120.00', $this->dbal->fetchOne('SELECT total_incl FROM order_order WHERE id = 10'));
        self::assertEquals(date('Y-m-d'), $this->dbal->fetchOne('SELECT issued_on FROM order_order WHERE id = 10'));

        // The invoice counter did not move: two documents, two sequences.
        self::assertEquals(7, $this->dbal->fetchOne(
            'SELECT counter FROM invoice_sequence WHERE company_id = 1 AND year = ?',
            [$year],
        ));
        self::assertEquals(2, $this->dbal->fetchOne(
            'SELECT counter FROM order_sequence WHERE company_id = 1 AND year = ?',
            [$year],
        ));
    }

    public function testAValidatedOrderRefusesEveryMutationWithAFlash(): void
    {
        $this->seedDraftWithLine(10);

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $detail = (string) $kernel->handle($this->get('/orders/10', $ada))->getBody();
        $token = $this->tokenFrom($detail);

        $kernel->handle($this->post('/orders/10/validate', ['_token' => $token], $ada));

        // Update, line add, delete, and a second validation: all flash+302.
        foreach ([
            ['/orders/10', ['thirdparty_id' => '1', 'issued_on' => '2026-08-01']],
            ['/orders/10/lines', ['label' => 'Late', 'quantity' => '1', 'unit_price' => '5.00', 'vat_rate' => '20.00']],
            ['/orders/10/delete', []],
            ['/orders/10/validate', []],
        ] as [$path, $body]) {
            $refused = $kernel->handle($this->post($path, [...$body, '_token' => $token], $ada));
            self::assertSame(302, $refused->getStatusCode(), $path);

            $after = (string) $kernel->handle($this->get('/orders/10', $ada))->getBody();
            self::assertStringContainsString('no longer change', $after, $path);
        }

        // Nothing moved: the order, its line and its number survived.
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM order_order'));
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM order_order_line'));
        self::assertEquals('validated', $this->dbal->fetchOne('SELECT status FROM order_order'));
    }

    private function seedDraftWithLine(int $id): void
    {
        $this->dbal->insert('order_order', [
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
