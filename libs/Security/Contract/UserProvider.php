<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Contract;

use Liminal\Lib\Security\Authentication\LoginCandidate;

/**
 * How the security lib reaches users without owning their storage. The
 * authentication module (phase 5) provides the real implementation and
 * overrides the default binding through definition layering.
 *
 * byId() runs on every authenticated request and must never haul the password
 * hash through memory; forLogin() runs once per login attempt and returns the
 * hash alongside the user, in a LoginCandidate that exists only for the
 * duration of the verification.
 */
interface UserProvider
{
    public function byId(int $id): ?AuthenticatedUser;

    public function forLogin(string $identifier): ?LoginCandidate;
}
