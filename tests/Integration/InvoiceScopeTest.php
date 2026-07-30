<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Liminal\Kernel;
use Liminal\Lib\Database\Exception\CompanyReassignmentException;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Module\Invoice\Entity\Invoice;
use Liminal\Module\Invoice\Entity\InvoiceLine;
use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;
use Throwable;

/**
 * The CompanyScoped promises on the invoice tables — and the cascade
 * semantics of the RESTRICT foreign key, pinned from measurement: MariaDB
 * resolves the company-delete diamond (invoices, lines, thirdparties and
 * sequences all cascade in one statement), while a TARGETED delete of a
 * thirdparty that documents still name is refused by the schema. The veto
 * hook answers that same case politely before the database has to.
 */
#[CoversNothing]
final class InvoiceScopeTest extends IntegrationTestCase
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
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping invoice scope test.');
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

        foreach ([[1, 1, 'TP-MAIN'], [2, 2, 'TP-ACME']] as [$id, $companyId, $code]) {
            $this->dbal->insert('thirdparty_thirdparty', [
                'id' => $id,
                'company_id' => $companyId,
                'code' => $code,
                'name' => $code,
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

        $invoice = new Invoice(1, new DateTimeImmutable('2026-07-31'), null);
        $this->em->persist($invoice);
        $this->em->flush();

        $this->em->persist(new InvoiceLine((int) $invoice->getId(), 1, 'Line', '1.00', '10.00', '20.00'));
        $this->em->flush();

        self::assertEquals(1, $this->dbal->fetchOne('SELECT company_id FROM invoice_invoice'));
        self::assertEquals(1, $this->dbal->fetchOne('SELECT company_id FROM invoice_invoice_line'));
    }

    public function testTheFilterFencesInvoiceReadsToTheAccessibleCompanies(): void
    {
        $this->seedInvoice(10, 1, 1);
        $this->seedInvoice(20, 2, 2);

        // Only MAIN accessible: ACME's invoice does not exist for the ORM.
        $this->context->switchTo(1, 1);
        $this->em->clear();

        $visible = $this->em->createQuery('SELECT i.id FROM ' . Invoice::class . ' i')->getSingleColumnResult();
        self::assertSame([10], array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $visible));

        // Widen to both: the fence, not the query, was the difference.
        $this->context->switchTo(1, 1, 2);
        $this->em->clear();

        $both = $this->em->createQuery('SELECT i.id FROM ' . Invoice::class . ' i ORDER BY i.id')->getSingleColumnResult();
        self::assertSame([10, 20], array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $both));
    }

    public function testCompanyIdIsWriteOnceOnInvoices(): void
    {
        $this->seedInvoice(10, 1, 1);
        $this->context->switchTo(1, 1, 2);

        $invoice = $this->em->createQuery(
            'SELECT i FROM ' . Invoice::class . ' i WHERE i.id = 10',
        )->getSingleResult();
        self::assertInstanceOf(Invoice::class, $invoice);

        // Force the field past the trait's guard, the way only a bug could.
        $this->em->getClassMetadata(Invoice::class)->setFieldValue($invoice, 'companyId', 2);

        $thrown = null;

        try {
            $this->em->flush();
        } catch (Throwable $caught) {
            $thrown = $caught;
        }

        self::assertInstanceOf(CompanyReassignmentException::class, $thrown);
        // The veto fires before the transaction opens: the EM survives.
        self::assertTrue($this->em->isOpen());
    }

    /**
     * The RESTRICT's real teeth, measured rather than assumed: MariaDB
     * resolves the cascade diamond — deleting the COMPANY takes invoices,
     * lines and thirdparties along in one statement, because the referencing
     * invoice dies in the same cascade. What the schema refuses is the
     * TARGETED deletion of a thirdparty that documents still name — exactly
     * the case the veto hook answers politely before the database has to.
     */
    public function testTheCompanyCascadeCarriesDocumentsButATargetedThirdpartyDeleteIsRefused(): void
    {
        $this->seedInvoice(10, 1, 1);

        $thrown = null;

        try {
            $this->dbal->executeStatement('DELETE FROM thirdparty_thirdparty WHERE id = 1');
        } catch (Throwable $caught) {
            $thrown = $caught;
        }

        self::assertInstanceOf(ForeignKeyConstraintViolationException::class, $thrown);

        $this->dbal->executeStatement('DELETE FROM core_company WHERE id = 1');

        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM invoice_invoice'));
        self::assertEquals(0, $this->dbal->fetchOne(
            'SELECT COUNT(*) FROM thirdparty_thirdparty WHERE company_id = 1',
        ));
    }

    public function testDeletingADraftTakesItsLinesAlong(): void
    {
        $this->seedInvoice(10, 1, 1);
        $this->dbal->insert('invoice_invoice_line', [
            'company_id' => 1,
            'invoice_id' => 10,
            'position' => 1,
            'label' => 'Line',
            'quantity' => '1.00',
            'unit_price' => '10.00',
            'vat_rate' => '20.00',
            'created_at' => '2026-07-31 00:00:00',
            'updated_at' => '2026-07-31 00:00:00',
        ]);

        $this->dbal->executeStatement('DELETE FROM invoice_invoice WHERE id = 10');

        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM invoice_invoice_line'));
    }

    public function testTheSequenceRidesTheCompanyCascade(): void
    {
        $this->dbal->insert('invoice_sequence', ['company_id' => 1, 'year' => 2026, 'counter' => 4]);

        $this->dbal->executeStatement('DELETE FROM core_company WHERE id = 1');

        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM invoice_sequence'));
        self::assertEquals(0, $this->dbal->fetchOne(
            'SELECT COUNT(*) FROM thirdparty_thirdparty WHERE company_id = 1',
        ));
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
