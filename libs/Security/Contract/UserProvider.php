<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Contract;

use Liminal\Lib\Security\Authentication\LoginCandidate;
use SensitiveParameter;

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

    /**
     * Rehash-on-login: the only write on this otherwise read-only contract.
     *
     * It belongs here rather than on an interface of its own because the new
     * hash must land in the same storage forLogin() read it from — two
     * bindings would be two things that must always agree about where that is.
     * Called only after a fully successful login, so a refused attempt never
     * costs a write.
     */
    public function rehash(int $id, #[SensitiveParameter] string $hash): void;
}
