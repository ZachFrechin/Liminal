<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Liminal\Kernel;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Module\Thirdparty\Entity\Thirdparty;
use Liminal\Module\Thirdparty\Repository\ThirdpartyPage;
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The repository's second scope layer against real SQL: the filter fences to
 * the accessible set, the repository narrows to the WORKING company — plus the
 * tree's first pagination arithmetic and its first escaped search.
 */
#[CoversNothing]
final class ThirdpartyRepositoryTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/../..';

    private Connection $dbal;

    private ThirdpartyRepository $repository;

    private CompanyContext $context;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping thirdparty repository test.');
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

        $context = $container->get(CompanyContext::class);
        self::assertInstanceOf(CompanyContext::class, $context);
        $this->context = $context;

        $this->repository = new ThirdpartyRepository($em, $context);
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    /**
     * THE second-layer proof: an actor entitled to two companies — whose
     * FILTER therefore admits both — lists only the company they work in.
     */
    public function testTheListShowsTheWorkingCompanyNotTheAccessibleSet(): void
    {
        $this->seedRow(1, 'HERE', 'In the working company');
        $this->seedRow(2, 'THERE', 'In the other accessible one');

        $this->context->switchTo(1, 1, 2);

        $page = $this->repository->page(1, null);

        self::assertSame(1, $page->total);
        self::assertSame('HERE', $page->items[0]->getCode());

        // Switching the working company flips the list, same accessible set.
        $this->context->switchTo(2, 1, 2);

        $page = $this->repository->page(1, null);

        self::assertSame(1, $page->total);
        self::assertSame('THERE', $page->items[0]->getCode());
    }

    public function testByIdRefusesAnotherAccessibleCompanysRow(): void
    {
        $this->seedRow(2, 'ELSEWHERE', 'Reachable but not current');
        $id = (int) $this->dbal->lastInsertId();

        $this->context->switchTo(1, 1, 2);

        // The FILTER would admit it (company 2 is accessible); the repository
        // narrows to the working company and finds nothing.
        self::assertNull($this->repository->byId($id));

        $this->context->switchTo(2, 1, 2);

        self::assertNotNull($this->repository->byId($id));
    }

    public function testPaginationClampsAndKeepsAStableOrder(): void
    {
        $this->context->switchTo(1, 1);

        // 30 rows with the SAME name: only the id tiebreaker keeps pages
        // disjoint. Codes T-01..T-30 seed in shuffled order.
        foreach ([15, 3, 28, 7, 22, 1, 30, 9, 18, 5, 26, 11, 2, 20, 13, 29, 4, 24, 8, 16, 6, 27, 10, 19, 12, 25, 14, 21, 17, 23] as $n) {
            $this->seedRow(1, sprintf('T-%02d', $n), 'Same Name');
        }

        $first = $this->repository->page(1, null);
        $second = $this->repository->page(2, null);

        self::assertSame(30, $first->total);
        self::assertSame(2, $first->pages);
        self::assertCount(25, $first->items);
        self::assertCount(5, $second->items);

        // Disjoint pages: no row repeats, none vanishes.
        $seen = array_map(static fn(Thirdparty $t): string => $t->getCode(), [...$first->items, ...$second->items]);
        self::assertCount(30, array_unique($seen));

        // Page requests outside reality clamp instead of erroring.
        self::assertSame(2, $this->repository->page(99, null)->page);
        self::assertSame(1, $this->repository->page(-3, null)->page);

        // An empty table lands on page 1 of 1 — never page 0.
        $this->dbal->executeStatement('DELETE FROM thirdparty_thirdparty');
        $empty = $this->repository->page(5, null);

        self::assertSame(0, $empty->total);
        self::assertSame(1, $empty->page);
        self::assertSame(1, $empty->pages);
        self::assertSame([], $empty->items);
    }

    public function testSearchMatchesNameCodeAndAliasAndEscapesWildcards(): void
    {
        $this->context->switchTo(1, 1);

        $this->seedRow(1, 'ALPHA', 'Alpha Industries');
        $this->seedRow(1, 'BETA', 'Beta Works', alias: 'The Alpha Reseller');
        $this->seedRow(1, 'GAMMA', 'Gamma Ltd');
        // Rows whose CONTENT contains LIKE wildcards.
        $this->seedRow(1, 'ODD-1', '100% Cotton');
        $this->seedRow(1, 'ODD-2', 'under_score gmbh');
        $this->seedRow(1, 'ODD-3', 'back\\slash & co');

        // Name and alias both match; code too.
        $codes = array_map(
            static fn(Thirdparty $t): string => $t->getCode(),
            $this->repository->page(1, 'alpha')->items,
        );
        self::assertSame(['ALPHA', 'BETA'], $codes);

        self::assertSame(1, $this->repository->page(1, 'GAMM')->total);

        // A wildcard in the QUERY is literal text: it matches only the row
        // that CONTAINS the character — never everything.
        self::assertSame(1, $this->repository->page(1, '100%')->total);
        self::assertSame(1, $this->repository->page(1, 'under_score')->total);
        self::assertSame(1, $this->repository->page(1, 'back\\slash')->total);
        self::assertSame(1, $this->repository->page(1, '%')->total);
        self::assertSame(1, $this->repository->page(1, '_')->total);

        // Blank input is no filter at all.
        self::assertSame(6, $this->repository->page(1, '   ')->total);
    }

    public function testCodeTakenSeesOnlyTheWorkingCompany(): void
    {
        $this->seedRow(1, 'SHARED', 'Mine');
        $mineId = (int) $this->dbal->lastInsertId();
        $this->seedRow(2, 'SHARED', 'Theirs');

        $this->context->switchTo(1, 1, 2);

        self::assertTrue($this->repository->codeTaken('SHARED'));
        // Excluding the row itself is how an edit re-checks its own code.
        self::assertFalse($this->repository->codeTaken('SHARED', $mineId));
        self::assertFalse($this->repository->codeTaken('FREE'));
    }

    public function testPerPageIsWhatThePageObjectSays(): void
    {
        self::assertSame(25, ThirdpartyPage::PER_PAGE);
    }

    private function seedRow(int $companyId, string $code, string $name, ?string $alias = null): void
    {
        $this->dbal->insert('thirdparty_thirdparty', [
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'alias' => $alias,
            'is_customer' => 0,
            'is_supplier' => 0,
            'is_active' => 1,
            'created_at' => '2026-07-30 00:00:00',
            'updated_at' => '2026-07-30 00:00:00',
        ]);
    }
}
