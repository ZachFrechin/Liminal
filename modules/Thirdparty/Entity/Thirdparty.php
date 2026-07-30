<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Liminal\Lib\Database\Scope\CompanyScoped;
use Liminal\Lib\Database\Scope\CompanyScopedTrait;

/**
 * A party this installation does business with: customer, supplier, both, or
 * neither yet.
 *
 * The first production CompanyScoped entity: the filter fences every read to
 * the accessible companies, prePersist stamps new rows with the working
 * company, and company_id is write-once — moving a thirdparty between
 * companies is not an ORM operation. On top of the fence, the repository
 * narrows every read to the CURRENT company: business data belongs to the
 * company you are working in.
 */
#[ORM\Entity]
#[ORM\Table(name: 'thirdparty_thirdparty')]
#[ORM\UniqueConstraint(name: 'uniq_thirdparty_thirdparty_company_code', columns: ['company_id', 'code'])]
#[ORM\Index(name: 'idx_thirdparty_thirdparty_company_name', columns: ['company_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
class Thirdparty implements CompanyScoped
{
    use CompanyScopedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $code;

    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 191, nullable: true)]
    private ?string $alias = null;

    #[ORM\Column(name: 'is_customer', type: Types::BOOLEAN)]
    private bool $customer = false;

    #[ORM\Column(name: 'is_supplier', type: Types::BOOLEAN)]
    private bool $supplier = false;

    #[ORM\Column(type: Types::STRING, length: 191, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $zip = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $town = null;

    #[ORM\Column(name: 'country_code', type: Types::STRING, length: 2, nullable: true)]
    private ?string $countryCode = null;

    #[ORM\Column(name: 'vat_number', type: Types::STRING, length: 32, nullable: true)]
    private ?string $vatNumber = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN)]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name)
    {
        $this->code = $code;
        $this->name = $name;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * The one "edit the record" gesture: everything a form may change except
     * the code (uniqueness-sensitive, its own move) and the company (never).
     */
    public function update(
        string $name,
        ?string $alias,
        bool $customer,
        bool $supplier,
        ?string $email,
        ?string $phone,
        ?string $address,
        ?string $zip,
        ?string $town,
        ?string $countryCode,
        ?string $vatNumber,
        ?string $notes,
    ): void {
        $this->name = $name;
        $this->alias = $alias;
        $this->customer = $customer;
        $this->supplier = $supplier;
        $this->email = $email;
        $this->phone = $phone;
        $this->address = $address;
        $this->zip = $zip;
        $this->town = $town;
        $this->countryCode = $countryCode;
        $this->vatNumber = $vatNumber;
        $this->notes = $notes;
    }

    /**
     * Separate from update(): a code change re-enters the per-company
     * uniqueness race and callers must treat it accordingly.
     */
    public function changeCode(string $code): void
    {
        $this->code = $code;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAlias(): ?string
    {
        return $this->alias;
    }

    public function isCustomer(): bool
    {
        return $this->customer;
    }

    public function isSupplier(): bool
    {
        return $this->supplier;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function getZip(): ?string
    {
        return $this->zip;
    }

    public function getTown(): ?string
    {
        return $this->town;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function touchOnUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
