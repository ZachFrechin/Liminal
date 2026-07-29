<?php

declare(strict_types=1);

namespace Liminal\Registry;

use Liminal\Registry\Exception\DuplicateContributionException;

/**
 * Collects the permissions modules declare, keyed by their unique code; the
 * security lib will resolve grants against this catalogue in phase 3.
 */
final class PermissionRegistry extends AbstractRegistry
{
    /** @var array<string, Permission> */
    private array $permissions = [];

    /**
     * @throws DuplicateContributionException when the permission code is already taken
     */
    public function add(Permission $permission): void
    {
        $this->assertMutable();

        if (isset($this->permissions[$permission->code])) {
            throw DuplicateContributionException::for(static::class, $permission->code);
        }

        $this->permissions[$permission->code] = $permission;
    }

    public function has(string $code): bool
    {
        return isset($this->permissions[$code]);
    }

    /** @return array<string, Permission> */
    public function all(): array
    {
        return $this->permissions;
    }
}
