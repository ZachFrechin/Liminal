<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authentication;

use Liminal\Lib\Security\Contract\AuthenticatedUser;
use Liminal\Lib\Security\Contract\UserProvider;
use Liminal\Lib\Security\Password\PasswordHasher;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionManager;
use SensitiveParameter;

/**
 * Login and logout mechanics. Three deliberate properties:
 *
 * - Timing equalisation: an unknown identifier still verifies against a real
 *   precomputed cost-12 bcrypt digest, so "no such user" and "wrong password"
 *   are indistinguishable by response time (the OWASP mitigation for username
 *   enumeration — a fake verify against '' would return in microseconds).
 * - The session id regenerates on every privilege change (login AND logout):
 *   whatever id existed before the change is dead after it.
 * - A user with no accessible company is refused exactly like a wrong
 *   password: offboarding is a data state, not a wiring error.
 *
 * Nothing here throttles attempts yet — login rate limiting is an explicit
 * phase-5 requirement on top of this surface.
 */
final readonly class Authenticator
{
    /**
     * A real bcrypt digest (cost 12, matching PASSWORD_DEFAULT on PHP 8.4) of
     * 64 random hex characters nobody knows. Kept in sync with the deployed
     * default cost so the dummy verification costs what a real one costs.
     */
    private const string DUMMY_HASH = '$2y$12$44Wy3/faVpEGjwrEsndRQ.NSz5YqgncyOhJb6Hp9ih/2t2t.cA22e';

    public function __construct(
        private UserProvider $users,
        private PasswordHasher $hasher,
        private SessionManager $sessions,
    ) {}

    public function attempt(
        string $identifier,
        #[SensitiveParameter]
        string $password,
        Session $session,
    ): ?AuthenticatedUser {
        $candidate = $this->users->forLogin($identifier);

        if ($candidate === null) {
            $this->hasher->verify($password, self::DUMMY_HASH);

            return null;
        }

        if (!$this->hasher->verify($password, $candidate->passwordHash)) {
            return null;
        }

        if ($candidate->user->accessibleCompanyIds() === []) {
            return null;
        }

        $this->sessions->regenerate($session);
        $session->setUserId($candidate->user->id());

        return $candidate->user;
    }

    /**
     * Keeps a live anonymous session (cleared and re-keyed) rather than
     * destroying it: the post-logout page wants flash messages and a fresh
     * CSRF token, and the old id's row dies at persist regardless.
     */
    public function logout(Session $session): void
    {
        $session->clear();
        $session->setUserId(null);
        $session->setCompanyId(null);
        $this->sessions->regenerate($session);
    }
}
