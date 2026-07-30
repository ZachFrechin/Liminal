<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user account, mapped for schema tooling and future ORM consumers — the
 * administration screens read and write through DBAL like everything else.
 *
 * Deliberately NOT CompanyScoped: users are reached across companies (a user may
 * belong to several), and the scope filter would make that unanswerable — see
 * the module's migration for the full reasoning. Deliberately not on the
 * request-security path either: that reads through DBAL, because the company
 * switch detaches entities one middleware after authentication.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_user')]
#[ORM\HasLifecycleCallbacks]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 191, unique: true)]
    private string $email;

    #[ORM\Column(name: 'password_hash', type: Types::STRING, length: 255)]
    private string $passwordHash;

    #[ORM\Column(name: 'display_name', type: Types::STRING, length: 191)]
    private string $displayName;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN)]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $email, string $passwordHash, string $displayName)
    {
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->displayName = $displayName;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function rename(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    #[ORM\PreUpdate]
    public function touchOnUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
