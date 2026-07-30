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
     * How to address this user on screen.
     *
     * On the contract rather than left to each module because current_user() is
     * a LIB helper rendered by LIB templates: without a name here, no shared
     * template could ever greet anyone, and every module wanting to would
     * re-fetch the user by id. Implementations must have it in hand already —
     * this is called on the hot path, never a lazy fetch.
     */
    public function displayName(): string;

    /**
     * @return list<int> never empty for a loggable user — the Authenticator
     *                   refuses the login otherwise, and the authentication
     *                   middleware logs out a user whose list emptied
     */
    public function accessibleCompanyIds(): array;
}
