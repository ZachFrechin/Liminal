<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Liminal\Kernel;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Database\Query\DqlListBuilder;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Module\Invoice\Repository\InvoiceRepository;
use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The two-layer scope on JOINED reads: the filter fences both aliases to the
 * accessible set, the repository narrows both to the current company — an
 * invoice is only listable where its thirdparty also belongs to the working
 * company, and search reaches the party's name through the arbitrary join.
 */
#[CoversNothing]
final class InvoiceRepositoryTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/../..';

    private Connection $dbal;

    private CompanyContext $context;

    private InvoiceRepository $repository;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping invoice repository test.');
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

        $this->repository = new InvoiceRepository($em, $this->context, new DqlListBuilder());
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    public function testThePageListsOnlyTheWorkingCompanyNewestFirst(): void
    {
        $this->seedInvoice(10, 1, 1, 'INV-2026-0001');
        $this->seedInvoice(11, 1, 1, null);
        $this->seedInvoice(20, 2, 2, 'INV-2026-0001');

        $page = $this->repository->page(1, null);

        self::assertSame(2, $page->total);
        self::assertSame([11, 10], array_map(static fn($invoice) => $invoice->getId(), $page->items));
    }

    public function testByIdRefusesAnotherAccessibleCompanysInvoice(): void
    {
        $this->seedInvoice(20, 2, 2, null);

        // ACME is ACCESSIBLE (the filter admits it) — but not current.
        self::assertNull($this->repository->byId(20));

        $this->context->switchTo(2, 1, 2);

        self::assertNotNull($this->repository->byId(20));
    }

    public function testSearchReachesTheNumberAndThePartyName(): void
    {
        $this->seedInvoice(10, 1, 1, 'INV-2026-0042');
        $this->seedInvoice(11, 1, 1, null);

        $byNumber = $this->repository->page(1, '0042');
        self::assertSame([10], array_map(static fn($invoice) => $invoice->getId(), $byNumber->items));

        $byParty = $this->repository->page(1, 'Wayne');
        self::assertSame(2, $byParty->total);

        // A literal % is content, not a wildcard: nothing carries one.
        self::assertSame(0, $this->repository->page(1, '%')->total);
    }

    public function testTheClampLandsStaleLinksOnTheLastPage(): void
    {
        $this->seedInvoice(10, 1, 1, null);

        $page = $this->repository->page(12, null);

        self::assertSame(1, $page->page);
        self::assertSame(1, $page->pages);
        self::assertFalse($page->hasNext());
    }

    public function testLinesComeBackInPositionOrderAndCountsServeTheVeto(): void
    {
        $this->seedInvoice(10, 1, 1, null);
        foreach ([[2, 'Second'], [1, 'First']] as [$position, $label]) {
            $this->dbal->insert('invoice_invoice_line', [
                'company_id' => 1,
                'invoice_id' => 10,
                'position' => $position,
                'label' => $label,
                'quantity' => '1.00',
                'unit_price' => '10.00',
                'vat_rate' => '20.00',
                'created_at' => '2026-07-31 00:00:00',
                'updated_at' => '2026-07-31 00:00:00',
            ]);
        }

        $invoice = $this->repository->byId(10);
        self::assertNotNull($invoice);

        $lines = $this->repository->linesOf($invoice);

        self::assertSame(['First', 'Second'], array_map(static fn($line) => $line->getLabel(), $lines));
        self::assertSame(3, $this->repository->nextPosition($invoice));
        self::assertSame(1, $this->repository->countForThirdparty(1));
        self::assertSame(0, $this->repository->countForThirdparty(2));
    }

    public function testThirdpartyNamesResolveWithinTheWorkingCompanyOnly(): void
    {
        self::assertSame([1 => 'Wayne Enterprises'], $this->repository->thirdpartyNamesFor([1, 2]));
        self::assertSame([], $this->repository->thirdpartyNamesFor([]));
    }

    private function seedInvoice(int $id, int $companyId, int $thirdpartyId, ?string $number): void
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
            'total_incl' => '0.00',
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);
    }
}
