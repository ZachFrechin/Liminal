<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Liminal\Lib\Database\Pagination\Page;
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

    /** Longer than any legitimate search, short enough to bound the LIKE. */
    private const int MAX_QUERY_LENGTH = 100;

    /**
     * Number and party name — the two things a human remembers an order by.
     */
    private const string SEARCH = "o.number LIKE :q ESCAPE '!' OR t.name LIKE :q ESCAPE '!'";

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompanyContext $context,
    ) {}

    /**
     * One page of the current company's orders, newest first.
     *
     * @return Page<Order>
     */
    public function page(int $page, ?string $query): Page
    {
        $needle = $this->needle($query);
        $join = ' JOIN ' . Thirdparty::class . ' t WITH t.id = o.thirdpartyId AND t.companyId = :company';
        $where = ' WHERE o.companyId = :company' . ($needle === null ? '' : ' AND (' . self::SEARCH . ')');

        $count = $this->entityManager->createQuery(
            'SELECT COUNT(o.id) FROM ' . Order::class . ' o' . $join . $where,
        );
        $count->setParameter('company', $this->context->currentId());

        if ($needle !== null) {
            $count->setParameter('q', $needle);
        }

        $total = (int) $count->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));

        $select = $this->entityManager->createQuery(
            'SELECT o FROM ' . Order::class . ' o' . $join . $where . ' ORDER BY o.id DESC',
        );
        $select->setParameter('company', $this->context->currentId());

        if ($needle !== null) {
            $select->setParameter('q', $needle);
        }

        $select->setFirstResult(($page - 1) * self::PER_PAGE);
        $select->setMaxResults(self::PER_PAGE);

        /** @var list<Order> $items */
        $items = $select->getResult();

        return new Page($items, $total, $page, $pages, self::PER_PAGE);
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
