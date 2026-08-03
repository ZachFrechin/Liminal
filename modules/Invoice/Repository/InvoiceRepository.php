<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Liminal\Lib\Database\Pagination\Page;
use Liminal\Lib\Database\Query\DqlListBuilder;
use Liminal\Lib\Database\Query\ListFilter;
use Liminal\Lib\Database\Query\ListRequest;
use Liminal\Lib\Database\Query\ListSchema;
use Liminal\Lib\Database\Query\SortDirection;
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

    /**
     * Number and party name — the two things a human remembers an invoice by.
     */
    private const string SEARCH = "i.number LIKE :q ESCAPE '!' OR t.name LIKE :q ESCAPE '!'";

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompanyContext $context,
        private DqlListBuilder $lists,
    ) {}

    /**
     * What the list admits. The default sort IS the tiebreaker — "newest
     * first" has always meant by id here, because id orders by creation and
     * breaks its own ties; naming it as a sort key keeps that contract
     * instead of quietly re-reading it as a date.
     *
     * Party sorts on the JOINED alias, which is exactly why the tiebreaker is
     * declared rather than inferred: two invoices to the same customer would
     * otherwise be free to swap between pages.
     */
    public static function schema(): ListSchema
    {
        return new ListSchema(
            alias: 'i',
            sorts: [
                'recent' => 'i.id',
                'number' => 'i.number',
                'party' => 't.name',
                'issued' => 'i.issuedOn',
                'due' => 'i.dueOn',
                'status' => 'i.status',
                'total' => 'i.totalIncl',
            ],
            tiebreaker: 'i.id',
            defaultSort: 'recent',
            defaultDirection: SortDirection::Descending,
            perPage: self::PER_PAGE,
            search: self::SEARCH,
            filters: [
                'status' => new ListFilter('status', 'invoice.list.status', 'invoice.status', [
                    Invoice::DRAFT => "i.status = 'draft'",
                    Invoice::VALIDATED => "i.status = 'validated'",
                ]),
            ],
        );
    }

    /**
     * One page of the current company's invoices.
     *
     * The join is an arbitrary DQL join narrowed to the same company on BOTH
     * sides — an invoice whose party drifted out of the working company
     * disappears from the list rather than appearing partyless.
     *
     * @return Page<Invoice>
     */
    public function pageOf(ListRequest $request): Page
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->join(Thirdparty::class, 't', 'WITH', 't.id = i.thirdpartyId AND t.companyId = :company')
            ->where('i.companyId = :company')
            ->setParameter('company', $this->context->currentId());

        return $this->lists->paginate($builder, Invoice::class, $request, self::schema());
    }

    /**
     * The two-parameter form, kept while the callers migrate.
     *
     * @return Page<Invoice>
     */
    public function page(int $page, ?string $query): Page
    {
        return $this->pageOf(ListRequest::fromQueryParams(
            ['page' => (string) $page, 'q' => $query ?? ''],
            self::schema(),
        ));
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
     * One line, provably of this invoice AND this company — a line id from
     * another document 404s like any cross-scope URL.
     */
    public function lineOf(Invoice $invoice, int $lineId): ?InvoiceLine
    {
        $result = $this->entityManager->createQuery(
            'SELECT l FROM ' . InvoiceLine::class
            . ' l WHERE l.id = :id AND l.invoiceId = :invoice AND l.companyId = :company',
        )
            ->setParameter('id', $lineId)
            ->setParameter('invoice', $invoice->getId())
            ->setParameter('company', $this->context->currentId())
            ->getOneOrNullResult();

        return $result instanceof InvoiceLine ? $result : null;
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
}
