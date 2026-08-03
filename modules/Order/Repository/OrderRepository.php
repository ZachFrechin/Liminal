<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Liminal\Lib\Database\Pagination\Page;
use Liminal\Lib\Database\Query\DqlListBuilder;
use Liminal\Lib\Database\Query\ListFilter;
use Liminal\Lib\Database\Query\ListRequest;
use Liminal\Lib\Database\Query\ListSchema;
use Liminal\Lib\Database\Query\SortDirection;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Module\Order\Entity\Order;
use Liminal\Module\Order\Entity\OrderLine;
use Liminal\Module\Thirdparty\Entity\Thirdparty;

/**
 * All reads and writes for orders and their lines — through the ORM, the
 * two-layer scope, the arbitrary JOIN … WITH: the invoice repository's
 * shape verbatim, plus the conversion questions (countForInvoice is the
 * invoice.deletion.veto's query).
 */
final readonly class OrderRepository
{
    public const int PER_PAGE = 25;

    /**
     * Number and party name — the two things a human remembers an order by.
     */
    private const string SEARCH = "o.number LIKE :q ESCAPE '!' OR t.name LIKE :q ESCAPE '!'";

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompanyContext $context,
        private DqlListBuilder $lists,
    ) {}

    /**
     * The invoice list's schema with three states instead of two — invoiced
     * is a state of its own here, and it is the one people come looking for.
     */
    public static function schema(): ListSchema
    {
        return new ListSchema(
            alias: 'o',
            sorts: [
                'recent' => 'o.id',
                'number' => 'o.number',
                'party' => 't.name',
                'issued' => 'o.issuedOn',
                'wanted' => 'o.wantedOn',
                'status' => 'o.status',
                'total' => 'o.totalIncl',
            ],
            tiebreaker: 'o.id',
            defaultSort: 'recent',
            defaultDirection: SortDirection::Descending,
            perPage: self::PER_PAGE,
            search: self::SEARCH,
            filters: [
                'status' => new ListFilter('status', 'order.filter.status', 'order.status', [
                    Order::DRAFT => "o.status = 'draft'",
                    Order::VALIDATED => "o.status = 'validated'",
                    Order::INVOICED => "o.status = 'invoiced'",
                ]),
            ],
        );
    }

    /**
     * One page of the current company's orders.
     *
     * @return Page<Order>
     */
    public function pageOf(ListRequest $request): Page
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join(Thirdparty::class, 't', 'WITH', 't.id = o.thirdpartyId AND t.companyId = :company')
            ->where('o.companyId = :company')
            ->setParameter('company', $this->context->currentId());

        return $this->lists->paginate($builder, Order::class, $request, self::schema());
    }

    /**
     * The two-parameter form, kept while the callers migrate.
     *
     * @return Page<Order>
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
    public function byId(int $id): ?Order
    {
        $result = $this->entityManager->createQuery(
            'SELECT o FROM ' . Order::class . ' o WHERE o.id = :id AND o.companyId = :company',
        )
            ->setParameter('id', $id)
            ->setParameter('company', $this->context->currentId())
            ->getOneOrNullResult();

        return $result instanceof Order ? $result : null;
    }

    /**
     * @return list<OrderLine>
     */
    public function linesOf(Order $order): array
    {
        /** @var list<OrderLine> $lines */
        $lines = $this->entityManager->createQuery(
            'SELECT l FROM ' . OrderLine::class
            . ' l WHERE l.orderId = :order AND l.companyId = :company ORDER BY l.position, l.id',
        )
            ->setParameter('order', $order->getId())
            ->setParameter('company', $this->context->currentId())
            ->getResult();

        return $lines;
    }

    /**
     * One line, provably of this order AND this company.
     */
    public function lineOf(Order $order, int $lineId): ?OrderLine
    {
        $result = $this->entityManager->createQuery(
            'SELECT l FROM ' . OrderLine::class
            . ' l WHERE l.id = :id AND l.orderId = :order AND l.companyId = :company',
        )
            ->setParameter('id', $lineId)
            ->setParameter('order', $order->getId())
            ->setParameter('company', $this->context->currentId())
            ->getOneOrNullResult();

        return $result instanceof OrderLine ? $result : null;
    }

    /**
     * MAX+1 — one person edits one draft; a concurrent add at worst shares a
     * position and the id tiebreaker keeps the order stable.
     */
    public function nextPosition(Order $order): int
    {
        $max = $this->entityManager->createQuery(
            'SELECT MAX(l.position) FROM ' . OrderLine::class
            . ' l WHERE l.orderId = :order AND l.companyId = :company',
        )
            ->setParameter('order', $order->getId())
            ->setParameter('company', $this->context->currentId())
            ->getSingleScalarResult();

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    /**
     * The thirdparty deletion veto's question: does the current company hold
     * orders naming this party?
     */
    public function countForThirdparty(int $thirdpartyId): int
    {
        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(o.id) FROM ' . Order::class
            . ' o WHERE o.thirdpartyId = :thirdparty AND o.companyId = :company',
        )
            ->setParameter('thirdparty', $thirdpartyId)
            ->setParameter('company', $this->context->currentId())
            ->getSingleScalarResult();
    }

    /**
     * The invoice deletion veto's question: does this invoice realise one of
     * the current company's orders? Conversion is same-company by
     * construction, so the narrowing loses nothing.
     */
    public function countForInvoice(int $invoiceId): int
    {
        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(o.id) FROM ' . Order::class
            . ' o WHERE o.invoiceId = :invoice AND o.companyId = :company',
        )
            ->setParameter('invoice', $invoiceId)
            ->setParameter('company', $this->context->currentId())
            ->getSingleScalarResult();
    }

    /**
     * The list's party column, one query for the whole page.
     *
     * @param list<int> $ids
     *
     * @return array<int, string>
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

    public function add(Order $order): void
    {
        $this->entityManager->persist($order);
    }

    public function addLine(OrderLine $line): void
    {
        $this->entityManager->persist($line);
    }

    public function removeLine(OrderLine $line): void
    {
        $this->entityManager->remove($line);
    }

    public function remove(Order $order): void
    {
        $this->entityManager->remove($order);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
