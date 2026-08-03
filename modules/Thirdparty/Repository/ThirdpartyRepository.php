<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Liminal\Lib\Database\Pagination\Page;
use Liminal\Lib\Database\Query\DqlListBuilder;
use Liminal\Lib\Database\Query\ListFilter;
use Liminal\Lib\Database\Query\ListRequest;
use Liminal\Lib\Database\Query\ListSchema;
use Liminal\Lib\Database\Query\SortDirection;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Module\Thirdparty\Entity\Thirdparty;

/**
 * All reads and writes for thirdparties — through the ORM, because the company
 * fence (filter, stamp, guards) lives nowhere else.
 *
 * Two layers of scope, on purpose. The Doctrine filter is the SECURITY
 * boundary: it fences every query to the ACCESSIBLE companies and is not this
 * class's to narrow. On top of it, every read here narrows explicitly to the
 * CURRENT company — business data belongs to the company you are working in,
 * which is what the account page's switcher switches. A query bug in this
 * class can therefore never leak past the accessible set; byId() of another
 * accessible company's row simply finds nothing, and the screen 404s.
 *
 * The scope is frozen while a handler runs: only the switch middleware (300)
 * calls switchTo(), the dispatcher sits at 1000, and nothing on the response
 * path touches the EntityManager.
 *
 * On flush failures: an in-transaction SQL error (the duplicate-code race)
 * CLOSES the EntityManager — but not the connection. close() only clears and
 * flags; the commit's rollback ends the transaction and the very same
 * connection (Connection::class IS the EM's, session persistence included)
 * keeps working in autocommit, so the failure flash reaches the store.
 * php-fpm's request-per-process confines the closed EM; a worker runtime
 * would need an EM-reset story before it could adopt this module. The scope
 * listener's own vetoes (cross-company, write-once) fire BEFORE the
 * transaction opens and leave the EM open — only in-transaction failures
 * close it.
 */
final readonly class ThirdpartyRepository
{
    public const int PER_PAGE = 25;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompanyContext $context,
        private DqlListBuilder $lists,
    ) {}

    /**
     * What the list admits: five sortable columns, two filters, one search.
     *
     * Rebuilt per call rather than held as a constant — a ListSchema carries
     * objects, and PHP constants cannot. The cost is a handful of allocations
     * on a request that is about to run two queries.
     */
    public static function schema(): ListSchema
    {
        return new ListSchema(
            alias: 't',
            sorts: [
                'code' => 't.code',
                'name' => 't.name',
                'town' => 't.town',
                'country' => 't.countryCode',
                'created' => 't.createdAt',
            ],
            tiebreaker: 't.id',
            defaultSort: 'name',
            defaultDirection: SortDirection::Ascending,
            perPage: self::PER_PAGE,
            search: self::SEARCH,
            filters: [
                // The choice labels reuse the catalogue the table cells
                // already render from: one word, one translation.
                'kind' => new ListFilter('kind', 'thirdparty.list.kind', 'thirdparty.kind', [
                    'customer' => 't.customer = true',
                    'supplier' => 't.supplier = true',
                ]),
                'status' => new ListFilter('status', 'thirdparty.list.status', 'thirdparty.state', [
                    'active' => 't.active = true',
                    'archived' => 't.active = false',
                ]),
            ],
        );
    }

    /**
     * One page of the current company's thirdparties.
     *
     * The company narrowing stays HERE: the list builder adds search, filters
     * and ordering, and is deliberately incapable of fencing a query. Two
     * boundaries would eventually disagree.
     *
     * @return Page<Thirdparty>
     */
    public function pageOf(ListRequest $request): Page
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Thirdparty::class, 't')
            ->where('t.companyId = :company')
            ->setParameter('company', $this->context->currentId());

        return $this->lists->paginate($builder, Thirdparty::class, $request, self::schema());
    }

    /**
     * The two-parameter form, kept while the callers migrate — and kept
     * honest: it goes through the same parsing the URL does.
     *
     * @return Page<Thirdparty>
     */
    public function page(int $page, ?string $query): Page
    {
        return $this->pageOf(ListRequest::fromQueryParams(
            ['page' => (string) $page, 'q' => $query ?? ''],
            self::schema(),
        ));
    }

    /**
     * Current company only — deliberately NOT find(): the filter would admit
     * any ACCESSIBLE company's row, and a URL pointing into another company
     * must 404 rather than leak across the working context.
     */
    public function byId(int $id): ?Thirdparty
    {
        $result = $this->entityManager->createQuery(
            'SELECT t FROM ' . Thirdparty::class . ' t WHERE t.id = :id AND t.companyId = :company',
        )
            ->setParameter('id', $id)
            ->setParameter('company', $this->context->currentId())
            ->getOneOrNullResult();

        return $result instanceof Thirdparty ? $result : null;
    }

    /**
     * The current company's active thirdparties, for a document's party
     * select. Unbounded on purpose for now: the day a company holds more
     * parties than a select can carry, the picker becomes a search — the
     * recorded gap, not a hidden LIMIT.
     *
     * @return list<Thirdparty>
     */
    public function activeForSelect(): array
    {
        /** @var list<Thirdparty> $items */
        $items = $this->entityManager->createQuery(
            'SELECT t FROM ' . Thirdparty::class
            . ' t WHERE t.companyId = :company AND t.active = true ORDER BY t.name, t.id',
        )
            ->setParameter('company', $this->context->currentId())
            ->getResult();

        return $items;
    }

    /**
     * The UX pre-check; the composite unique constraint remains the judge,
     * and callers still catch the race at flush.
     */
    public function codeTaken(string $code, ?int $excludeId = null): bool
    {
        $dql = 'SELECT COUNT(t.id) FROM ' . Thirdparty::class
            . ' t WHERE t.companyId = :company AND t.code = :code'
            . ($excludeId === null ? '' : ' AND t.id <> :exclude');

        $query = $this->entityManager->createQuery($dql)
            ->setParameter('company', $this->context->currentId())
            ->setParameter('code', $code);

        if ($excludeId !== null) {
            $query->setParameter('exclude', $excludeId);
        }

        return (int) $query->getSingleScalarResult() > 0;
    }

    public function add(Thirdparty $thirdparty): void
    {
        $this->entityManager->persist($thirdparty);
    }

    public function remove(Thirdparty $thirdparty): void
    {
        $this->entityManager->remove($thirdparty);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * The three searched fields. ESCAPE is explicit and names LikeNeedle's
     * character — the needle escapes for it, so the two are one contract.
     */
    private const string SEARCH = "t.name LIKE :q ESCAPE '!' OR t.code LIKE :q ESCAPE '!' OR t.alias LIKE :q ESCAPE '!'";
}
