<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Liminal\Lib\Database\Pagination\Page;
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

    /** Longer than any legitimate search, short enough to bound the LIKE. */
    private const int MAX_QUERY_LENGTH = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompanyContext $context,
    ) {}

    /**
     * One page of the current company's thirdparties, optionally filtered.
     *
     * The page number is clamped AFTER counting: total=0 must land on page 1
     * of 1 (never page 0 and a negative offset), and a stale link to page 12
     * of what is now 3 pages lands on page 3. ORDER BY name alone would let
     * equal names swap between pages on MariaDB — id breaks the tie.
     *
     * @return Page<Thirdparty>
     */
    public function page(int $page, ?string $query): Page
    {
        $needle = $this->needle($query);
        $where = 't.companyId = :company' . ($needle === null ? '' : ' AND (' . self::SEARCH . ')');

        $count = $this->entityManager->createQuery(
            'SELECT COUNT(t.id) FROM ' . Thirdparty::class . ' t WHERE ' . $where,
        );
        $count->setParameter('company', $this->context->currentId());

        if ($needle !== null) {
            $count->setParameter('q', $needle);
        }

        $total = (int) $count->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));

        $select = $this->entityManager->createQuery(
            'SELECT t FROM ' . Thirdparty::class . ' t WHERE ' . $where . ' ORDER BY t.name, t.id',
        );
        $select->setParameter('company', $this->context->currentId());

        if ($needle !== null) {
            $select->setParameter('q', $needle);
        }

        $select->setFirstResult(($page - 1) * self::PER_PAGE);
        $select->setMaxResults(self::PER_PAGE);

        /** @var list<Thirdparty> $items */
        $items = $select->getResult();

        return new Page($items, $total, $page, $pages, self::PER_PAGE);
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
     * The three searched fields. ESCAPE is explicit, and the escape character
     * is '!' — a backslash would have to survive PHP, DQL and SQL quoting in
     * agreement, and MariaDB refuses what comes out the other end.
     */
    private const string SEARCH = "t.name LIKE :q ESCAPE '!' OR t.code LIKE :q ESCAPE '!' OR t.alias LIKE :q ESCAPE '!'";

    /**
     * A bound parameter escapes nothing: % and _ inside the VALUE still act
     * as wildcards, so the user's text is escaped before the wrapping ones
     * are added. With an explicit ESCAPE char, a backslash in the value is
     * ordinary text.
     */
    private function needle(?string $query): ?string
    {
        $query = trim($query ?? '');

        if ($query === '') {
            return null;
        }

        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($query, 0, self::MAX_QUERY_LENGTH));

        return '%' . $escaped . '%';
    }
}
