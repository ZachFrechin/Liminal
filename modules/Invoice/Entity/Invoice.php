<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Liminal\Lib\Database\Money\DocumentTotals;
use Liminal\Lib\Database\Scope\CompanyScoped;
use Liminal\Lib\Database\Scope\CompanyScopedTrait;
use Liminal\Module\Invoice\Exception\InvoiceModuleException;

/**
 * A customer invoice: a draft until validated, a legal record after.
 *
 * thirdparty_id is a plain column, deliberately NOT a mapped association —
 * the tree has none, and the first one would drag lazy-loading through the
 * company filter for no gain. Reads that need the party's name join
 * explicitly in DQL; integrity lives in the schema's foreign key.
 *
 * The draft guards here are defence in depth: handlers refuse mutations of
 * validated invoices with a flash BEFORE the entity has to throw. Reaching
 * these exceptions means a handler forgot its guard — wiring, not content.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_invoice')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_invoice_company_number', columns: ['company_id', 'number'])]
#[ORM\Index(name: 'idx_invoice_invoice_company_status', columns: ['company_id', 'status'])]
#[ORM\Index(name: 'idx_invoice_invoice_company_issued', columns: ['company_id', 'issued_on'])]
#[ORM\Index(name: 'idx_invoice_invoice_company_thirdparty', columns: ['company_id', 'thirdparty_id'])]
#[ORM\HasLifecycleCallbacks]
class Invoice implements CompanyScoped
{
    use CompanyScopedTrait;

    public const string DRAFT = 'draft';

    public const string VALIDATED = 'validated';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'thirdparty_id', type: Types::INTEGER)]
    private int $thirdpartyId;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $status = self::DRAFT;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(name: 'issued_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $issuedOn;

    #[ORM\Column(name: 'due_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $dueOn;

    /** Doctrine maps DECIMAL to string — no float ever holds an amount. */
    #[ORM\Column(name: 'total_excl', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $totalExcl = '0.00';

    #[ORM\Column(name: 'total_tax', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $totalTax = '0.00';

    #[ORM\Column(name: 'total_incl', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $totalIncl = '0.00';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(int $thirdpartyId, DateTimeImmutable $issuedOn, ?DateTimeImmutable $dueOn)
    {
        $this->thirdpartyId = $thirdpartyId;
        $this->issuedOn = $issuedOn;
        $this->dueOn = $dueOn;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * The one "edit the draft header" gesture: the party and the dates.
     *
     * @throws InvoiceModuleException when the invoice is validated
     */
    public function update(int $thirdpartyId, DateTimeImmutable $issuedOn, ?DateTimeImmutable $dueOn): void
    {
        $this->assertDraft();

        $this->thirdpartyId = $thirdpartyId;
        $this->issuedOn = $issuedOn;
        $this->dueOn = $dueOn;
    }

    /**
     * Totals follow the lines: recomputed through the invoice.total.compute
     * hook on every line write, stored so lists never recompute.
     *
     * @throws InvoiceModuleException when the invoice is validated
     */
    public function refreshTotals(DocumentTotals $totals): void
    {
        $this->assertDraft();

        $this->storeTotals($totals);
    }

    /**
     * The one-way door. Émission IS validation: the issue date refreshes to
     * the validation day, and the number was minted from that day's year
     * inside the same transaction that claimed the counter.
     *
     * @throws InvoiceModuleException when the invoice is validated already
     */
    public function validate(string $number, DocumentTotals $totals, DateTimeImmutable $issuedOn): void
    {
        $this->assertDraft();

        $this->status = self::VALIDATED;
        $this->number = $number;
        $this->issuedOn = $issuedOn;
        $this->storeTotals($totals);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getThirdpartyId(): int
    {
        return $this->thirdpartyId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function getIssuedOn(): DateTimeImmutable
    {
        return $this->issuedOn;
    }

    public function getDueOn(): ?DateTimeImmutable
    {
        return $this->dueOn;
    }

    public function getTotalExcl(): string
    {
        return $this->totalExcl;
    }

    public function getTotalTax(): string
    {
        return $this->totalTax;
    }

    public function getTotalIncl(): string
    {
        return $this->totalIncl;
    }

    #[ORM\PreUpdate]
    public function touchOnUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    private function storeTotals(DocumentTotals $totals): void
    {
        $this->totalExcl = $totals->totalExclAsDecimal();
        $this->totalTax = $totals->totalTaxAsDecimal();
        $this->totalIncl = $totals->totalInclAsDecimal();
    }

    /**
     * @throws InvoiceModuleException
     */
    private function assertDraft(): void
    {
        if (!$this->isDraft()) {
            throw InvoiceModuleException::notDraft($this->id ?? 0);
        }
    }
}
