<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Security;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Liminal\Lib\Security\Authentication\AuthEvent;
use Liminal\Lib\Security\Authentication\ClientContext;
use Liminal\Lib\Security\Contract\AuthEventLog;

/**
 * Appends to core_auth_event. Insert-only: there is no update or delete path in
 * code, because an audit trail nobody can edit is the only kind worth keeping.
 */
final readonly class DbalAuthEventLog implements AuthEventLog
{
    /**
     * @param Closure(): Connection $connection
     */
    public function __construct(private Closure $connection) {}

    public function record(
        AuthEvent $event,
        string $identifier,
        ?int $userId,
        ClientContext $client,
    ): void {
        ($this->connection)()->insert('core_auth_event', [
            'occurred_at' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
            'event' => $event->value,
            'user_id' => $userId,
            // Stored as presented so an investigation can see what was tried,
            // normalised so it joins with the throttle's subject.
            'identifier' => mb_substr(DbalUserProvider::normalise($identifier), 0, 191),
            'ip_address' => $client->ipAddress,
            'user_agent' => $client->userAgent,
        ]);
    }
}
