<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A company. Deliberately NOT CompanyScoped: it is the scoping axis itself, so
 * filtering it by company_id would be circular.
 *
 * updated_at maintains itself through the PreUpdate lifecycle callback — no
 * mutator has to remember it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_company')]
#[ORM\HasLifecycleCallbacks]
class Company
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32, unique: true)]
    private string $code;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $zip = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $town = null;

    #[ORM\Column(name: 'country_code', type: Types::STRING, length: 2, nullable: true, options: ['fixed' => true])]
    private ?string $countryCode = null;

    #[ORM\Column(name: 'vat_number', type: Types::STRING, length: 32, nullable: true)]
    private ?string $vatNumber = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $registration = null;

    #[ORM\Column(name: 'legal_mentions', type: Types::TEXT, nullable: true)]
    private ?string $legalMentions = null;

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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    /**
     * The one "edit the identity" gesture — what a legal document says about
     * its issuer. The name stays rename()'s: two mutations, two audit facts.
     */
    public function updateIdentity(
        ?string $address,
        ?string $zip,
        ?string $town,
        ?string $countryCode,
        ?string $vatNumber,
        ?string $registration,
        ?string $legalMentions,
    ): void {
        $this->address = $address;
        $this->zip = $zip;
        $this->town = $town;
        $this->countryCode = $countryCode;
        $this->vatNumber = $vatNumber;
        $this->registration = $registration;
        $this->legalMentions = $legalMentions;
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

    public function getRegistration(): ?string
    {
        return $this->registration;
    }

    public function getLegalMentions(): ?string
    {
        return $this->legalMentions;
    }

    #[ORM\PreUpdate]
    public function touchOnUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
