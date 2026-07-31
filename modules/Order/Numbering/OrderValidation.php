<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Numbering;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Database\Sequence\YearlySequence;
use Liminal\Module\Order\Entity\Order;
use Liminal\Module\Order\Repository\OrderRepository;
use Liminal\Module\Order\Totals\OrderTotalsService;

/**
 * The first one-way door, atomically: the counter claim (DBAL) and the
 * order's freeze (ORM flush) are ONE commit — the EntityManager and the
 * container's Connection are the same connection, so the upsert joins the
 * wrap. The concurrency-bearing code lives once, in the lib's
 * YearlySequence; this service owns only the module's table and format.
 *
 * Orders do not owe the accountant a gap-free series the way invoices do —
 * but the machinery costs nothing to share, so they get it anyway.
 *
 * Failure mode, written down: ANY throw inside the wrap closes the
 * EntityManager (the vendor's finally closes, then rolls back). The
 * connection survives, the flash reaches the store, and the caller must
 * not touch the EntityManager afterwards.
 *
 * Émission IS validation: the issue date refreshes to the validation day,
 * and the number is minted from that day's year.
 */
final readonly class OrderValidation
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderRepository $orders,
        private OrderTotalsService $totals,
        private CompanyContext $context,
    ) {}

    /**
     * Freezes the draft and returns the number it will carry forever. The
     * handler guards status and lines first — reaching this with a
     * non-draft order throws through the entity's own gate.
     */
    public function validate(Order $order): string
    {
        $issuedOn = new DateTimeImmutable('today');
        $year = (int) $issuedOn->format('Y');
        $companyId = $this->context->currentId();
        $totals = $this->totals->totalsFor($order, $this->orders->linesOf($order));

        return $this->entityManager->wrapInTransaction(
            function () use ($order, $totals, $issuedOn, $year, $companyId): string {
                $counter = YearlySequence::claim(
                    $this->entityManager->getConnection(),
                    'order_sequence',
                    $companyId,
                    $year,
                );

                $number = sprintf('CMD-%d-%04d', $year, $counter);
                $order->validate($number, $totals, $issuedOn);

                // wrapInTransaction flushes on the way out: the order's
                // freeze and the counter claim commit together.
                return $number;
            },
        );
    }
}
