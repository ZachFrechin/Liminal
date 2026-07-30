<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Contract;

use Liminal\Lib\Security\Authentication\AuthEvent;
use Liminal\Lib\Security\Authentication\ClientContext;

/**
 * The append-only audit trail of the authentication path.
 *
 * Deliberately not columns on the session row: a session is deleted at logout,
 * regeneration or GC — exactly when an investigation would want to read it.
 */
interface AuthEventLog
{
    public function record(
        AuthEvent $event,
        string $identifier,
        ?int $userId,
        ClientContext $client,
    ): void;
}
