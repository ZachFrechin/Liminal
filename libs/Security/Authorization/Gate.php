<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authorization;

use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Lib\Security\Contract\PermissionResolver;
use Liminal\Lib\Security\Exception\UndeclaredPermissionException;
use Liminal\Registry\PermissionRegistry;

/**
 * Answers "may the current user do this, here?" — the declared permission, the
 * request's user, the request's company.
 *
 * Deliberately read-only: there is no authorize() that throws an
 * HttpException. Baking the render path into a lib service would make the Gate
 * unusable from the console, and phase 4 will add the throwing helper at the
 * controller layer where HTTP identity belongs.
 */
final readonly class Gate
{
    public function __construct(
        private CurrentUser $currentUser,
        private CompanyContext $context,
        private PermissionResolver $resolver,
        private PermissionRegistry $permissions,
    ) {}

    /**
     * @throws UndeclaredPermissionException when the code was never contributed
     */
    public function allows(string $permission): bool
    {
        if (!$this->permissions->has($permission)) {
            // A typo must fail loud: returning false would read as "denied"
            // and hide the wiring mistake forever.
            throw UndeclaredPermissionException::for($permission);
        }

        $user = $this->currentUser->get();

        return $user !== null && $this->resolver->allows($user, $permission, $this->context->currentId());
    }
}
