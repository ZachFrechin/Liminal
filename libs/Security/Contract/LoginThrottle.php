<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Contract;

use Liminal\Lib\Security\Authentication\ClientContext;

/**
 * Rate limiting for the login path, implemented by whatever owns storage.
 *
 * The contract lives in the lib and the Authenticator consults it, so rate
 * limiting is not something each login page has to remember: a third-party
 * login form gets it by using the Authenticator at all.
 *
 * State must NOT live in the session. A failed request persists nothing (the
 * error handler is the outermost middleware), and an attacker who drops the
 * cookie would reset the counter — so implementations count somewhere durable.
 */
interface LoginThrottle
{
    /**
     * @return int|null seconds to wait, or null when the attempt may proceed
     */
    public function check(string $identifier, ClientContext $client): ?int;

    public function recordFailure(string $identifier, ClientContext $client): void;

    /**
     * Clears what a legitimate login should clear — by contract the identifier's
     * counter only. Clearing the address counter too would let an attacker spray
     * many accounts from one address, log into their own, and reset the spray.
     */
    public function recordSuccess(string $identifier, ClientContext $client): void;
}
