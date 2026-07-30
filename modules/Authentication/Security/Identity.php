<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Security;

use Liminal\Lib\Security\Contract\AuthenticatedUser;

/**
 * The request's identity: a value object, deliberately NOT the User entity.
 *
 * EntityManagerFactory registers onSwitch(fn => $em->clear()), and the company
 * switch middleware calls switchTo() unconditionally on every request — one
 * middleware AFTER authentication. So an entity hydrated by byId() would be
 * detached by the time a template greets the user, with lazy associations that
 * fail or read empty. Everything the request needs is copied out here instead.
 */
final readonly class Identity implements AuthenticatedUser
{
    /**
     * @param list<int> $companyIds
     */
    public function __construct(
        private int $id,
        private string $email,
        private string $displayName,
        private array $companyIds,
    ) {}

    public function id(): int
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    /**
     * @return list<int>
     */
    public function accessibleCompanyIds(): array
    {
        return $this->companyIds;
    }
}
