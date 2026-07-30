<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authentication;

use Liminal\Lib\Security\Contract\AuthEventLog;

/**
 * The default until a module brings storage: nothing is recorded. A lib cannot
 * invent a table, and an installation with no authentication module has no
 * logins to audit.
 */
final readonly class NullAuthEventLog implements AuthEventLog
{
    public function record(
        AuthEvent $event,
        string $identifier,
        ?int $userId,
        ClientContext $client,
    ): void {}
}
