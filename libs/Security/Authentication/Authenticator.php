<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authentication;

use Liminal\Lib\Security\Contract\AuthEventLog;
use Liminal\Lib\Security\Contract\LoginThrottle;
use Liminal\Lib\Security\Contract\UserProvider;
use Liminal\Lib\Security\Csrf\CsrfTokenManager;
use Liminal\Lib\Security\Password\PasswordHasher;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionManager;
use SensitiveParameter;

/**
 * Login and logout mechanics. Five deliberate properties:
 *
 * - The throttle is consulted BEFORE any password verification: a locked
 *   identifier must not buy an attacker 250ms of bcrypt CPU per try, which is
 *   a denial-of-service surface as much as a credential-stuffing one.
 * - Timing equalisation: an unknown identifier still verifies against a real
 *   precomputed cost-12 bcrypt digest, so "no such user" and "wrong password"
 *   are indistinguishable by response time (the OWASP mitigation for username
 *   enumeration — a fake verify against '' would return in microseconds).
 * - The session id regenerates on every privilege change (login AND logout):
 *   whatever id existed before the change is dead after it.
 * - A user with no accessible company is refused exactly like a wrong
 *   password: offboarding is a data state, not a wiring error.
 * - Every outcome records exactly one audit event, and rate limiting is not
 *   something each login page has to remember: using this class at all is
 *   what buys both.
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
        private LoginThrottle $throttle,
        private AuthEventLog $events,
    ) {}

    public function attempt(
        string $identifier,
        #[SensitiveParameter]
        string $password,
        Session $session,
        ClientContext $client,
    ): LoginResult {
        $retryAfter = $this->throttle->check($identifier, $client);

        if ($retryAfter !== null) {
            $this->events->record(AuthEvent::LoginThrottled, $identifier, null, $client);

            return LoginResult::throttled($retryAfter);
        }

        $candidate = $this->users->forLogin($identifier);

        if ($candidate === null) {
            $this->hasher->verify($password, self::DUMMY_HASH);

            return $this->refuse($identifier, $client);
        }

        if (!$this->hasher->verify($password, $candidate->passwordHash)) {
            return $this->refuse($identifier, $client);
        }

        if ($candidate->user->accessibleCompanyIds() === []) {
            return $this->refuse($identifier, $client);
        }

        $this->sessions->regenerate($session);
        $session->setUserId($candidate->user->id());
        // Rotate the CSRF token on privilege elevation (OWASP): the payload
        // survives regeneration by design, so the retirement must be explicit;
        // the next token() call generates a fresh one.
        $session->remove(CsrfTokenManager::KEY);

        if ($this->hasher->needsRehash($candidate->passwordHash)) {
            // Only after a fully successful login: a refused attempt never
            // costs a write, and the new hash lands in the same storage
            // forLogin() read it from.
            $this->users->rehash($candidate->user->id(), $this->hasher->hash($password));
        }

        $this->throttle->recordSuccess($identifier, $client);
        $this->events->record(AuthEvent::LoginGranted, $identifier, $candidate->user->id(), $client);

        return LoginResult::granted($candidate->user);
    }

    /**
     * Keeps a live anonymous session (cleared and re-keyed) rather than
     * destroying it: the post-logout page wants flash messages and a fresh
     * CSRF token, and the old id's row dies at persist regardless.
     */
    public function logout(Session $session, ClientContext $client): void
    {
        $userId = $session->userId();

        $session->clear();
        $session->setUserId(null);
        $session->setCompanyId(null);
        $this->sessions->regenerate($session);

        if ($userId !== null) {
            $this->events->record(AuthEvent::LoggedOut, '', $userId, $client);
        }
    }

    private function refuse(string $identifier, ClientContext $client): LoginResult
    {
        $this->throttle->recordFailure($identifier, $client);
        $this->events->record(AuthEvent::LoginRefused, $identifier, null, $client);

        return LoginResult::refused();
    }
}
