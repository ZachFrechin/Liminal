<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Liminal\Kernel;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Module\Order\Repository\OrderRepository;
use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The two-layer scope on the order repository, lightened where the invoice
 * mirror already pins the identical mechanics — plus the two veto questions
 * (party and invoice counts), both narrowed to the working company.
 */
#[CoversNothing]
final class OrderRepositoryTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/../..';

    private Connection $dbal;

    private CompanyContext $context;

    private OrderRepository $repository;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping order repository test.');
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

        foreach ([[1, 1, 'Wayne Enterprises'], [2, 2, 'Stark Industries']] as [$id, $companyId, $name]) {
            $this->dbal->insert('thirdparty_thirdparty', [
                'id' => $id,
                'company_id' => $companyId,
                'code' => 'TP-' . $id,
                'name' => $name,
                'is_customer' => 1,
                'is_supplier' => 0,
                'is_active' => 1,
                'created_at' => '2026-07-31 00:00:00',
                'updated_at' => '2026-07-31 00:00:00',
            ]);
        }

        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $context = $container->get(CompanyContext::class);
        self::assertInstanceOf(CompanyContext::class, $context);
        $this->context = $context;
        $this->context->switchTo(1, 1, 2);

        $this->repository = new OrderRepository($em, $this->context);
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    public function testThePageListsOnlyTheWorkingCompanyAndSearchReachesTheParty(): void
    {
        $this->seedOrder(10, 1, 1, 'CMD-2026-0001');
        $this->seedOrder(11, 1, 1, null);
        $this->seedOrder(20, 2, 2, 'CMD-2026-0001');

        $page = $this->repository->page(1, null);

        self::assertSame(2, $page->total);
        self::assertSame([11, 10], array_map(static fn($order) => $order->getId(), $page->items));

        $byNumber = $this->repository->page(1, '0001');
        self::assertSame([10], array_map(static fn($order) => $order->getId(), $byNumber->items));

        $byParty = $this->repository->page(1, 'Wayne');
        self::assertSame(2, $byParty->total);
    }

    public function testByIdRefusesAnotherAccessibleCompanysOrder(): void
    {
        $this->seedOrder(20, 2, 2, null);

        self::assertNull($this->repository->byId(20));

        $this->context->switchTo(2, 1, 2);

        self::assertNotNull($this->repository->byId(20));
    }

    public function testTheTwoVetoQuestionsCountWithinTheWorkingCompany(): void
    {
        $this->seedInvoice(50, 1, 1);
        $this->seedOrder(10, 1, 1, 'CMD-2026-0001', status: 'invoiced', invoiceId: 50);
        $this->seedOrder(11, 1, 1, null);

        self::assertSame(2, $this->repository->countForThirdparty(1));
        self::assertSame(0, $this->repository->countForThirdparty(2));
        self::assertSame(1, $this->repository->countForInvoice(50));
        self::assertSame(0, $this->repository->countForInvoice(999));
    }

    private function seedOrder(int $id, int $companyId, int $thirdpartyId, ?string $number, string $status = '', ?int $invoiceId = null): void
    {
        $this->dbal->insert('order_order', [
            'id' => $id,
            'company_id' => $companyId,
            'thirdparty_id' => $thirdpartyId,
            'status' => $status !== '' ? $status : ($number === null ? 'draft' : 'validated'),
            'number' => $number,
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
