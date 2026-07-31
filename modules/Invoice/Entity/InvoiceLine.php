<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Liminal\Lib\Database\Money\Contract\DocumentLine;
use Liminal\Lib\Database\Scope\CompanyScoped;
use Liminal\Lib\Database\Scope\CompanyScopedTrait;

/**
 * One line of a draft or validated invoice. Scoped in its own right — the
 * fence guards lines even when a query never touches their invoice.
 *
 * No mutators: a line is written once and removed whole (edit = remove +
 * re-add, recorded gap). invoice_id is a plain column like the invoice's
 * thirdparty_id — integrity is the schema's foreign key, which also takes
 * the lines along when a draft is deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_invoice_line')]
#[ORM\Index(name: 'idx_invoice_line_company_invoice', columns: ['company_id', 'invoice_id', 'position'])]
#[ORM\HasLifecycleCallbacks]
class InvoiceLine implements CompanyScoped, DocumentLine
{
    use CompanyScopedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'invoice_id', type: Types::INTEGER)]
    private int $invoiceId;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $label;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $quantity;

    #[ORM\Column(name: 'unit_price', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $unitPrice;

    #[ORM\Column(name: 'vat_rate', type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $vatRate;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        int $invoiceId,
        int $position,
        string $label,
        string $quantity,
        string $unitPrice,
        string $vatRate,
    ) {
        $this->invoiceId = $invoiceId;
        $this->position = $position;
        $this->label = $label;
        $this->quantity = $quantity;
        $this->unitPrice = $unitPrice;
        $this->vatRate = $vatRate;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoiceId(): int
    {
        return $this->invoiceId;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function getVatRate(): string
    {
        return $this->vatRate;
    }

    #[ORM\PreUpdate]
    public function touchOnUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
