<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Liminal\Lib\Database\Money\Contract\DocumentLine;
use Liminal\Lib\Database\Scope\CompanyScoped;
use Liminal\Lib\Database\Scope\CompanyScopedTrait;

/**
 * One line of an order. Scoped in its own right, no mutators (edit = remove
 * + re-add, the recorded gap), order_id a plain column — the invoice line
 * precedent, byte for byte, and a DocumentLine like it: the lib calculator
 * reads both the same way.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_order_line')]
#[ORM\Index(name: 'idx_order_line_company_order', columns: ['company_id', 'order_id', 'position'])]
#[ORM\HasLifecycleCallbacks]
class OrderLine implements CompanyScoped, DocumentLine
{
    use CompanyScopedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'order_id', type: Types::INTEGER)]
    private int $orderId;

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
        int $orderId,
        int $position,
        string $label,
        string $quantity,
        string $unitPrice,
        string $vatRate,
    ) {
        $this->orderId = $orderId;
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

    public function getOrderId(): int
    {
        return $this->orderId;
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
