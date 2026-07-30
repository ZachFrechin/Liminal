<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Liminal\Kernel;
use Liminal\Lib\Database\Exception\CompanyReassignmentException;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Module\Thirdparty\Entity\Thirdparty;
use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;
use Throwable;

/**
 * The phase-1 promises, finally proven on a PRODUCTION table: everything the
 * CompanyScoped machinery guaranteed on test widgets now holds on
 * thirdparty_thirdparty — the stamp, the fence, the write-once gate, and the
 * first composite per-company uniqueness.
 */
#[CoversNothing]
final class ThirdpartyScopeTest extends IntegrationTestCase
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
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping thirdparty scope test.');
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
                'created_at' => '2026-07-30 00:00:00',
                'updated_at' => '2026-07-30 00:00:00',
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

    public function testPersistStampsTheWorkingCompany(): void
    {
        $this->context->switchTo(1, 1, 2);

        $this->em->persist(new Thirdparty('ACME-CORP', 'Acme Corporation'));
        $this->em->flush();

        self::assertEquals(1, $this->dbal->fetchOne(
            "SELECT company_id FROM thirdparty_thirdparty WHERE code = 'ACME-CORP'",
        ));
    }

    /**
     * The first composite per-company uniqueness in the tree: the SAME code is
     * welcome in another company and refused within one — case-insensitively,
     * through the server's collation.
     */
    public function testTheCodeIsUniquePerCompanyNotGlobally(): void
    {
        $this->context->switchTo(1, 1, 2);
        $this->em->persist(new Thirdparty('ACME-CORP', 'Acme seen from MAIN'));
        $this->em->flush();

        // Same code, other company: fine. (switchTo clears the EM — the
        // previous entity is detached, its row already committed.)
        $this->context->switchTo(2, 1, 2);
        $this->em->persist(new Thirdparty('ACME-CORP', 'Acme seen from ACME'));
        $this->em->flush();

        self::assertEquals(2, $this->dbal->fetchOne('SELECT COUNT(*) FROM thirdparty_thirdparty'));

        // Same code, same company, different case: refused by the collation.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->em->persist(new Thirdparty('acme-corp', 'Impostor'));
        $this->em->flush();
    }

    public function testTheFilterFencesReadsToTheAccessibleCompanies(): void
    {
        $this->seedRow(1, 'IN-MAIN', 'Visible');
        $this->seedRow(2, 'IN-ACME', 'Invisible');

        // Accessible = {1} only: company 2's row does not exist for this scope.
        $this->context->switchTo(1, 1);

        $names = $this->em->createQuery(
            'SELECT t.name FROM ' . Thirdparty::class . ' t ORDER BY t.name',
        )->getSingleColumnResult();

        self::assertSame(['Visible'], $names);

        // Widening the scope widens the read — the filter parameter follows.
        $this->context->switchTo(1, 1, 2);

        $widened = $this->em->createQuery(
            'SELECT t FROM ' . Thirdparty::class . ' t',
        )->getResult();
        self::assertIsArray($widened);
        self::assertCount(2, $widened);
    }

    public function testCompanyIdIsWriteOnceOnTheRealTable(): void
    {
        $this->seedRow(1, 'PINNED', 'Pinned to MAIN');
        $this->context->switchTo(1, 1, 2);

        $thirdparty = $this->em->createQuery(
            'SELECT t FROM ' . Thirdparty::class . " t WHERE t.code = 'PINNED'",
        )->getSingleResult();
        self::assertInstanceOf(Thirdparty::class, $thirdparty);

        // Force the field past the trait's guard, the way only a bug could.
        $metadata = $this->em->getClassMetadata(Thirdparty::class);
        $metadata->setFieldValue($thirdparty, 'companyId', 2);

        $thrown = null;

        try {
            $this->em->flush();
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(
            CompanyReassignmentException::class,
            $thrown,
            'Cross-company reassignment must not reach the database.',
        );

        // The veto fires before the transaction opens: the EM stays open and
        // the database never saw a statement.
        self::assertTrue($this->em->isOpen());
        self::assertEquals(1, $this->dbal->fetchOne(
            "SELECT company_id FROM thirdparty_thirdparty WHERE code = 'PINNED'",
        ));
    }

    public function testDeletingACompanyTakesItsThirdpartiesAlong(): void
    {
        $this->seedRow(1, 'STAYS', 'Stays');
        $this->seedRow(2, 'GOES', 'Goes');

        $this->dbal->executeStatement('DELETE FROM core_company WHERE id = 2');

        self::assertSame(
            ['STAYS'],
            $this->dbal->fetchFirstColumn('SELECT code FROM thirdparty_thirdparty'),
        );
    }

    private function seedRow(int $companyId, string $code, string $name): void
    {
        $this->dbal->insert('thirdparty_thirdparty', [
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'is_customer' => 0,
            'is_supplier' => 0,
            'is_active' => 1,
            'created_at' => '2026-07-30 00:00:00',
            'updated_at' => '2026-07-30 00:00:00',
        ]);
    }
}
