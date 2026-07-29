<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Contract;

/**
 * Decides whether a user holds a declared permission in a company. The
 * authentication module (phase 5) implements it against its grant storage;
 * until then the lib binds a resolver that denies everything.
 */
interface PermissionResolver
{
    public function allows(AuthenticatedUser $user, string $permission, int $companyId): bool;
}
