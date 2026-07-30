<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Liminal\Lib\Database\Pagination\Page;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Module\Invoice\Entity\Invoice;
use Liminal\Module\Invoice\Entity\InvoiceLine;
use Liminal\Module\Thirdparty\Entity\Thirdparty;

/**
 * All reads and writes for invoices and their lines — through the ORM,
 * because the company fence lives nowhere else. Two layers of scope, the
 * thirdparty precedent verbatim: the Doctrine filter fences to the
 * ACCESSIBLE companies, and every read here narrows to the CURRENT one.
 *
 * Joined reads use an arbitrary DQL join (JOIN … WITH), never a mapped
 * association: the filter constrains BOTH aliases independently, and this
 * class narrows both to the current company — an invoice whose thirdparty
 * drifted out of the working company simply disappears from joined results.
 */
final readonly class InvoiceRepository
{
    public const int PER_PAGE = 25;

    /** Longer than any legitimate search, short enough to bound the LIKE. */
    private const int MAX_QUERY_LENGTH = 100;

    /**
     * Number and party name — the two things a human remembers an invoice by.
     */
    private const string SEARCH = "i.number LIKE :q ESCAPE '!' OR t.name LIKE :q ESCAPE '!'";

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompanyContext $context,
    ) {}

    /**
     * One page of the current company's invoices, newest first — id already
     * orders by creation and breaks its own ties.
     *
     * @return Page<Invoice>
     */
    public function page(int $page, ?string $query): Page
    {
        $needle = $this->needle($query);
        $join = ' JOIN ' . Thirdparty::class . ' t WITH t.id = i.thirdpartyId AND t.companyId = :company';
        $where = ' WHERE i.companyId = :company' . ($needle === null ? '' : ' AND (' . self::SEARCH . ')');

        $count = $this->entityManager->createQuery(
            'SELECT COUNT(i.id) FROM ' . Invoice::class . ' i' . $join . $where,
        );
        $count->setParameter('company', $this->context->currentId());

        if ($needle !== null) {
            $count->setParameter('q', $needle);
        }

        $total = (int) $count->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));

        $select = $this->entityManager->createQuery(
            'SELECT i FROM ' . Invoice::class . ' i' . $join . $where . ' ORDER BY i.id DESC',
        );
        $select->setParameter('company', $this->context->currentId());

        if ($needle !== null) {
            $select->setParameter('q', $needle);
        }

        $select->setFirstResult(($page - 1) * self::PER_PAGE);
        $select->setMaxResults(self::PER_PAGE);

        /** @var list<Invoice> $items */
        $items = $select->getResult();

        return new Page($items, $total, $page, $pages, self::PER_PAGE);
    }

    /**
     * Current company only — a URL pointing into another company must 404
     * rather than leak across the working context.
     */
    public function byId(int $id): ?Invoice
    {
        $result = $this->entityManager->createQuery(
            'SELECT i FROM ' . Invoice::class . ' i WHERE i.id = :id AND i.companyId = :company',
        )
            ->setParameter('id', $id)
            ->setParameter('company', $this->context->currentId())
            ->getOneOrNullResult();

        return $result instanceof Invoice ? $result : null;
    }

    /**
     * @return list<InvoiceLine>
     */
    public function linesOf(Invoice $invoice): array
    {
        /** @var list<InvoiceLine> $lines */
        $lines = $this->entityManager->createQuery(
            'SELECT l FROM ' . InvoiceLine::class
            . ' l WHERE l.invoiceId = :invoice AND l.companyId = :company ORDER BY l.position, l.id',
        )
            ->setParameter('invoice', $invoice->getId())
            ->setParameter('company', $this->context->currentId())
            ->getResult();

        return $lines;
    }

    /**
     * MAX+1 — one person edits one draft; a concurrent add at worst shares a
     * position and the id tiebreaker keeps the order stable.
     */
    public function nextPosition(Invoice $invoice): int
    {
        $max = $this->entityManager->createQuery(
            'SELECT MAX(l.position) FROM ' . InvoiceLine::class
            . ' l WHERE l.invoiceId = :invoice AND l.companyId = :company',
        )
            ->setParameter('invoice', $invoice->getId())
            ->setParameter('company', $this->context->currentId())
            ->getSingleScalarResult();

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    /**
     * The deletion veto's question: does the current company hold documents
     * naming this party?
     */
    public function countForThirdparty(int $thirdpartyId): int
    {
        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(i.id) FROM ' . Invoice::class
            . ' i WHERE i.thirdpartyId = :thirdparty AND i.companyId = :company',
        )
            ->setParameter('thirdparty', $thirdpartyId)
            ->setParameter('company', $this->context->currentId())
            ->getSingleScalarResult();
    }

    /**
     * The list's party column, one query for the whole page.
     *
     * @param list<int> $ids
     *
     * @return array<int, string> thirdparty id => name; a party outside the
     *                            current company simply has no entry
     */
    public function thirdpartyNamesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<array{id: int, name: string}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT t.id, t.name FROM ' . Thirdparty::class
            . ' t WHERE t.id IN (:ids) AND t.companyId = :company',
        )
            ->setParameter('ids', $ids)
            ->setParameter('company', $this->context->currentId())
            ->getArrayResult();

        $names = [];
        foreach ($rows as $row) {
            $names[$row['id']] = $row['name'];
        }

        return $names;
    }

    public function add(Invoice $invoice): void
    {
        $this->entityManager->persist($invoice);
    }

    public function addLine(InvoiceLine $line): void
    {
        $this->entityManager->persist($line);
    }

    public function removeLine(InvoiceLine $line): void
    {
        $this->entityManager->remove($line);
    }

    public function remove(Invoice $invoice): void
    {
        $this->entityManager->remove($invoice);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * A bound parameter escapes nothing: % and _ inside the VALUE still act
     * as wildcards, so the user's text is escaped before the wrapping ones
     * are added.
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
