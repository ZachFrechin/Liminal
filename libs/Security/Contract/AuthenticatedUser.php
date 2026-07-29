<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Contract;

/**
 * The identity the security lib works with; the authentication module
 * (phase 5) implements it on its user entity — the same way entities
 * implement the Database lib's CompanyScoped.
 */
interface AuthenticatedUser
{
    public function id(): int;

    /**
     * @return list<int> never empty for a loggable user — the Authenticator
     *                   refuses the login otherwise, and the authentication
     *                   middleware logs out a user whose list emptied
     */
    public function accessibleCompanyIds(): array;
}
