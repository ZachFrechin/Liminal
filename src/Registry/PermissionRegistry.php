<?php

declare(strict_types=1);

namespace Liminal\Registry;

final class PermissionRegistry extends AbstractRegistry
{
    /** @var array<string, Permission> */
    private array $permissions = [];

    public function add(Permission $permission): void
    {
        $this->assertMutable();

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
