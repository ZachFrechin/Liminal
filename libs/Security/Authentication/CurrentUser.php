<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authentication;

use Liminal\Lib\Security\Contract\AuthenticatedUser;

/**
 * The request's authenticated user, shared and mutable by design — the
 * CompanyContext precedent: services like the Gate cannot read request
 * attributes, and this holder is the bridge. The authentication middleware
 * assigns it UNCONDITIONALLY on every request (set or cleared), so no state
 * ever survives into the next request of a worker-mode runtime.
 */
final class CurrentUser
{
    private ?AuthenticatedUser $user = null;

    public function set(?AuthenticatedUser $user): void
    {
        $this->user = $user;
    }

    public function get(): ?AuthenticatedUser
    {
        return $this->user;
    }
}
