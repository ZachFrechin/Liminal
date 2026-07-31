<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authentication;

use Liminal\Lib\Security\Contract\AuthenticatedUser;

/**
 * An identity established BEFORE the session-based authentication runs,
 * travelling as a request attribute — per-request and immutable by
 * construction. Never carried through the CurrentUser holder: the holder is
 * a process-wide singleton whose unconditional per-request assignment is the
 * worker-mode safety invariant, and reading "already set" state out of it
 * would turn one request's leftover identity into the next request's
 * credential.
 *
 * Downstream contracts keyed on this attribute: AuthenticationMiddleware
 * adopts the identity instead of the session's, CsrfMiddleware exempts the
 * request (no cookie was involved, so there is nothing to forge cross-origin),
 * and CompanySwitchMiddleware scopes from the request instead of the session.
 * The attribute must therefore only ever be set from a VALIDATED credential.
 */
final readonly class PreAuthentication
{
    public const string ATTRIBUTE = 'liminal.pre_authentication';

    public function __construct(public AuthenticatedUser $user) {}
}
