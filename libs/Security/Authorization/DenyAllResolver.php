<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authorization;

use Liminal\Lib\Security\Contract\AuthenticatedUser;
use Liminal\Lib\Security\Contract\PermissionResolver;

/**
 * The safe default until the authentication module (phase 5) brings real
 * grants: nothing is permitted. Fail closed — an unwired authorization layer
 * must never read as "allowed".
 */
final readonly class DenyAllResolver implements PermissionResolver
{
    public function allows(AuthenticatedUser $user, string $permission, int $companyId): bool
    {
        return false;
    }
}
