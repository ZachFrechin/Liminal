<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Contract;

use SensitiveParameter;

/**
 * How the security lib validates a bearer credential without owning its
 * storage. The authentication module provides the real implementation and
 * overrides the default binding through definition layering — the fifth
 * instance of the contract-plus-inert-default pattern after UserProvider,
 * PermissionResolver, LoginThrottle and AuthEventLog.
 *
 * Hashing is the implementation's business alone: the raw token crosses this
 * boundary exactly once per request and is never stored, logged or echoed.
 * The implementation also owns the freshness bookkeeping (expiry check,
 * last-used touch) — the state lives in the row, not in the caller.
 */
interface TokenProvider
{
    /**
     * The id of the user this token authenticates, or null when the token is
     * unknown, expired or revoked. Deliberately an id and not a user: loading
     * (and thereby re-checking) the account is the caller's job through
     * UserProvider::byId(), so offboarding rules live once.
     */
    public function authenticate(#[SensitiveParameter] string $rawToken): ?int;
}
