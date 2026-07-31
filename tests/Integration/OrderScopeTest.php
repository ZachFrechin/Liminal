<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Liminal\Kernel;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Module\Order\Entity\Order;
use Liminal\Module\Order\Entity\OrderLine;
use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;
use Throwable;

/**
 * The scoped invariants on the order tables — a lightened mirror (stamp,
 * fence and write-once are pinned twice already on identical machinery) —
 * plus the NEW cascade diamond MEASURED: order_order.invoice_id RESTRICT
 * joins the company-delete graph, and InnoDB's sibling-FK ordering is not
 * contractual, so the tests pin what the engine actually does.
 */
#[CoversNothing]
final class OrderScopeTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/../..';

    private Connection $dbal;

    private EntityManagerInterface $em;

    private CompanyContext $context;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping order scope test.');
        }

        $this->dbal = $this->connection();

        $this->dropAllTables($this->dbal);

        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);

        $container = new Kernel(self::ROOT)->container();

        $runner = $container->get(MigrationRunner::class);
        self::assertInstanceOf(MigrationRunner::class, $runner);
        $runner->migrateToLatest();

        foreach ([['MAIN', 1], ['ACME', 2]] as [$code, $id]) {
            $this->dbal->insert('core_company', [
                'id' => $id,
                'code' => $code,
                'name' => $code,
                'created_at' => '2026-07-31 00:00:00',
                'updated_at' => '2026-07-31 00:00:00',
            ]);
        }

        foreach ([[1, 1], [2, 2]] as [$id, $companyId]) {
            $this->dbal->insert('thirdparty_thirdparty', [
                'id' => $id,
                'company_id' => $companyId,
                'code' => 'TP-' . $id,
                'name' => 'TP-' . $id,
                'is_customer' => 1,
                'is_supplier' => 0,
                'is_active' => 1,
                'created_at' => '2026-07-31 00:00:00',
                'updated_at' => '2026-07-31 00:00:00',
            ]);
        }

        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $context = $container->get(CompanyContext::class);
        self::assertInstanceOf(CompanyContext::class, $context);
        $this->context = $context;
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    public function testPersistStampsBothEntitiesWithTheWorkingCompany(): void
    {
        $this->context->switchTo(1, 1, 2);

        $order = new Order(1, new DateTimeImmutable('2026-07-31'), null);
        $this->em->persist($order);
        $this->em->flush();

        $this->em->persist(new OrderLine((int) $order->getId(), 1, 'Line', '1.00', '10.00', '20.00'));
        $this->em->flush();

        self::assertEquals(1, $this->dbal->fetchOne('SELECT company_id FROM order_order'));
        self::assertEquals(1, $this->dbal->fetchOne('SELECT company_id FROM order_order_line'));
    }

    public function testTheFilterFencesOrderReads(): void
    {
        $this->seedOrder(10, 1, 1);
        $this->seedOrder(20, 2, 2);

        $this->context->switchTo(1, 1);
        $this->em->clear();

        $visible = $this->em->createQuery('SELECT o.id FROM ' . Order::class . ' o')->getSingleColumnResult();
        self::assertSame([10], array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $visible));
    }

    public function testDeletingADraftTakesItsLinesAlong(): void
    {
        $this->seedOrder(10, 1, 1);
        $this->dbal->insert('order_order_line', [
            'company_id' => 1,
            'order_id' => 10,
            'position' => 1,
            'label' => 'Line',
            'quantity' => '1.00',
            'unit_price' => '10.00',
            'vat_rate' => '20.00',
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);

        $this->dbal->executeStatement('DELETE FROM order_order WHERE id = 10');

        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM order_order_line'));
    }

    /**
     * The new diamond, MEASURED — and this one blocks: InnoDB cascades the
     * invoice row before the order that points at it, so a company holding a
     * CONVERTED order cannot be deleted in one statement (1451), unlike the
     * phase-9 thirdparty diamond which resolves. Both facts are pinned so a
     * MariaDB upgrade cannot change either silently. The recorded rule
     * absorbs it: the future company-deletion service deletes documents
     * first, applicatively — proven below by hand.
     */
    public function testTheConversionDiamondIsPinned(): void
    {
        $this->seedInvoice(50, 1, 1);
        $this->seedOrder(10, 1, 1, status: 'invoiced', invoiceId: 50);

        // Targeted: the schema always protects the pointer.
        $thrown = null;

        try {
            $this->dbal->executeStatement('DELETE FROM invoice_invoice WHERE id = 50');
        } catch (Throwable $caught) {
            $thrown = $caught;
        }

        self::assertInstanceOf(ForeignKeyConstraintViolationException::class, $thrown);

        // The single-statement company delete trips the same RESTRICT.
        $blocked = null;

        try {
            $this->dbal->executeStatement('DELETE FROM core_company WHERE id = 1');
        } catch (Throwable $caught) {
            $blocked = $caught;
        }

        self::assertInstanceOf(ForeignKeyConstraintViolationException::class, $blocked);

        // The applicative order the deletion service will follow: documents
        // first (orders release their pointers), then the company cascades.
        $this->dbal->executeStatement('DELETE FROM order_order WHERE company_id = 1');
        $this->dbal->executeStatement('DELETE FROM core_company WHERE id = 1');

        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM invoice_invoice'));
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM order_order'));
    }

    private function seedOrder(int $id, int $companyId, int $thirdpartyId, string $status = 'draft', ?int $invoiceId = null): void
    {
        $this->dbal->insert('order_order', [
            'id' => $id,
            'company_id' => $companyId,
            'thirdparty_id' => $thirdpartyId,
            'status' => $status,
            'issued_on' => '2026-07-31',
            'invoice_id' => $invoiceId,
            'total_excl' => '0.00',
            'total_tax' => '0.00',
            'total_incl' => '0.00',
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
    }

    private function seedInvoice(int $id, int $companyId, int $thirdpartyId): void
    {
        $this->dbal->insert('invoice_invoice', [
            'id' => $id,
            'company_id' => $companyId,
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
