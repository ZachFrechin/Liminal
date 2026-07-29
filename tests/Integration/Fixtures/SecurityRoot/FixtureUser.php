<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Lib\Security\Contract\AuthenticatedUser;

/**
 * In-memory user for the security fixtures: alice reaches both companies,
 * bob only the second — the pair the company-scope HTTP proof plays.
 */
final readonly class FixtureUser implements AuthenticatedUser
{
    /**
     * @param list<int> $companies
     */
    public function __construct(
        private int $id,
        private array $companies,
    ) {}

    public function id(): int
    {
        return $this->id;
    }

    public function accessibleCompanyIds(): array
    {
        return $this->companies;
    }
}
