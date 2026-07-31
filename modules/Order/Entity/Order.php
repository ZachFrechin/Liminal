<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Liminal\Lib\Database\Money\DocumentTotals;
use Liminal\Lib\Database\Scope\CompanyScoped;
use Liminal\Lib\Database\Scope\CompanyScopedTrait;
use Liminal\Module\Order\Exception\OrderModuleException;

/**
 * A customer order: a draft until validated, a record after, an invoiced
 * record once — the one state more than an invoice. Two one-way doors:
 * validate() freezes and numbers a draft; markInvoiced() stamps a VALIDATED
 * order with the invoice it became, once and forever (unlinking is a
 * recorded gap, and the invoice.deletion.veto keeps the pointer honest).
 *
 * Invariant, single-writer enforced: status = INVOICED ⇔ invoice_id IS NOT
 * NULL — both are set in the same mutation, one flush, no desync window.
 * The redundancy is earned: the status feeds badges and the (company,
 * status) index, the pointer feeds the foreign key, the veto and the link.
 *
 * thirdparty_id and invoice_id are plain columns, never mapped associations
 * — the invoice precedent. The guards here are defence in depth: handlers
 * refuse with a flash before the entity has to throw.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_order')]
#[ORM\UniqueConstraint(name: 'uniq_order_order_company_number', columns: ['company_id', 'number'])]
#[ORM\Index(name: 'idx_order_order_company_status', columns: ['company_id', 'status'])]
#[ORM\Index(name: 'idx_order_order_company_issued', columns: ['company_id', 'issued_on'])]
#[ORM\Index(name: 'idx_order_order_company_thirdparty', columns: ['company_id', 'thirdparty_id'])]
#[ORM\Index(name: 'idx_order_order_company_invoice', columns: ['company_id', 'invoice_id'])]
#[ORM\HasLifecycleCallbacks]
class Order implements CompanyScoped
{
    use CompanyScopedTrait;

    public const string DRAFT = 'draft';

    public const string VALIDATED = 'validated';

    public const string INVOICED = 'invoiced';

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

    #[ORM\Column(name: 'wanted_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $wantedOn;

    #[ORM\Column(name: 'invoice_id', type: Types::INTEGER, nullable: true)]
    private ?int $invoiceId = null;

    #[ORM\Column(name: 'invoiced_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $invoicedAt = null;

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

    public function __construct(int $thirdpartyId, DateTimeImmutable $issuedOn, ?DateTimeImmutable $wantedOn)
    {
        $this->thirdpartyId = $thirdpartyId;
        $this->issuedOn = $issuedOn;
        $this->wantedOn = $wantedOn;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * The one "edit the draft header" gesture: the party and the dates.
     *
     * @throws OrderModuleException when the order left the draft state
     */
    public function update(int $thirdpartyId, DateTimeImmutable $issuedOn, ?DateTimeImmutable $wantedOn): void
    {
        $this->assertDraft();

        $this->thirdpartyId = $thirdpartyId;
        $this->issuedOn = $issuedOn;
        $this->wantedOn = $wantedOn;
    }

    /**
     * Totals follow the lines: recomputed through the order.total.compute
     * hook on every line write, stored so lists never recompute.
     *
     * @throws OrderModuleException when the order left the draft state
     */
    public function refreshTotals(DocumentTotals $totals): void
    {
        $this->assertDraft();

        $this->storeTotals($totals);
    }

    /**
     * The first one-way door. Émission IS validation, the invoice rule.
     *
     * @throws OrderModuleException when the order left the draft state
     */
    public function validate(string $number, DocumentTotals $totals, DateTimeImmutable $issuedOn): void
    {
        $this->assertDraft();

        $this->status = self::VALIDATED;
        $this->number = $number;
        $this->issuedOn = $issuedOn;
        $this->storeTotals($totals);
    }

    /**
     * The second one-way door: a VALIDATED order becomes the invoice it
     * turned into. Status, pointer and timestamp move together — one
     * mutation, one flush, no desync window.
     *
     * @throws OrderModuleException when the order is not validated
     */
    public function markInvoiced(int $invoiceId): void
    {
        if (!$this->isValidated()) {
            throw OrderModuleException::notValidated($this->id ?? 0);
        }

        $this->status = self::INVOICED;
        $this->invoiceId = $invoiceId;
        $this->invoicedAt = new DateTimeImmutable();
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /**
     * Strictly VALIDATED — false again once invoiced: this is the state the
     * conversion door opens from, not "was validated at some point".
     */
    public function isValidated(): bool
    {
        return $this->status === self::VALIDATED;
    }

    public function isInvoiced(): bool
    {
        return $this->status === self::INVOICED;
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

    public function getWantedOn(): ?DateTimeImmutable
    {
        return $this->wantedOn;
    }

    public function getInvoiceId(): ?int
    {
        return $this->invoiceId;
    }

    public function getInvoicedAt(): ?DateTimeImmutable
    {
        return $this->invoicedAt;
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
     * @throws OrderModuleException
     */
    private function assertDraft(): void
    {
        if (!$this->isDraft()) {
            throw OrderModuleException::notDraft($this->id ?? 0);
        }
    }
}
