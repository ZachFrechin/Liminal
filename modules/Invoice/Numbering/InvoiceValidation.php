<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Numbering;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Module\Invoice\Entity\Invoice;
use Liminal\Module\Invoice\Repository\InvoiceRepository;
use Liminal\Module\Invoice\Totals\InvoiceTotalsService;

/**
 * The one-way door, atomically: the tree's first wrapInTransaction, because
 * the counter claim (DBAL) and the invoice's freeze (ORM flush) must be ONE
 * commit — the EntityManager and the container's Connection are the same
 * connection, so the upsert joins the wrap.
 *
 * Gap-free by construction: the ON DUPLICATE KEY upsert X-locks the
 * (company, year) row until the OUTER commit, serialising concurrent
 * validations — the loser resumes on the committed counter, numbers stay
 * consecutive, and any failure rolls the claim back with everything else.
 *
 * Failure mode, written down: ANY throw inside the wrap — a scope-listener
 * veto included — closes the EntityManager (the vendor's finally closes,
 * then rolls back). The connection survives, the flash reaches the store,
 * and the caller must not touch the EntityManager afterwards. This is the
 * deliberate inverse of the bare-flush rule the scope tests pin.
 *
 * Émission IS validation: the issue date refreshes to the validation day,
 * and the number is minted from that day's year — a December draft
 * validated in January belongs to January's sequence, not to a closed
 * millésime.
 */
final readonly class InvoiceValidation
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private InvoiceRepository $invoices,
        private InvoiceTotalsService $totals,
        private CompanyContext $context,
    ) {}

    /**
     * Freezes the draft and returns the number it will carry forever. The
     * handler guards status and lines first — reaching this with a validated
     * invoice throws through the entity's own gate.
     */
    public function validate(Invoice $invoice): string
    {
        $issuedOn = new DateTimeImmutable('today');
        $year = (int) $issuedOn->format('Y');
        $companyId = $this->context->currentId();
        $totals = $this->totals->totalsFor($invoice, $this->invoices->linesOf($invoice));

        return $this->entityManager->wrapInTransaction(
            function () use ($invoice, $totals, $issuedOn, $year, $companyId): string {
                $connection = $this->entityManager->getConnection();

                // The throttle precedent: an atomic increment, never a
                // read-modify-write. MariaDB's VALUES() on purpose.
                $connection->executeStatement(
                    'INSERT INTO invoice_sequence (company_id, year, counter) VALUES (?, ?, 1)'
                    . ' ON DUPLICATE KEY UPDATE counter = counter + 1',
                    [$companyId, $year],
                );

                // Reads its own locked write; race-safe under the held lock.
                $raw = $connection->fetchOne(
                    'SELECT counter FROM invoice_sequence WHERE company_id = ? AND year = ?',
                    [$companyId, $year],
                );
                $counter = is_numeric($raw) ? (int) $raw : 1;

                $number = sprintf('INV-%d-%04d', $year, $counter);
                $invoice->validate($number, $totals, $issuedOn);

                // wrapInTransaction flushes on the way out: the invoice's
                // freeze and the counter claim commit together.
                return $number;
            },
        );
    }
}
