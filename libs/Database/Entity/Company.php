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

    #[ORM\PreUpdate]
    public function touchOnUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
