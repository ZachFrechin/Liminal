<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Audit;

use Closure;
use Doctrine\DBAL\Connection;
use Liminal\Lib\Hook\Contract\TriggerListener;
use Liminal\Lib\Hook\TriggerEvent;

/**
 * The triggers' first production consumer: every fired trigger becomes one
 * row of core_audit_event, catch-all by subscription so a module added next
 * year is audited with zero wiring.
 *
 * Insert-only — there is no update or delete path in code, because an audit
 * trail nobody can edit is the only kind worth keeping. Registered at the
 * anchor priority so the forensic row exists before any other listener gets
 * a chance to kill the process; and best-effort by construction — the fire
 * is post-commit, so aborting the deed is impossible by design.
 */
final readonly class AuditTrailListener implements TriggerListener
{
    /**
     * @param Closure(): Connection $connection
     */
    public function __construct(private Closure $connection) {}

    public function react(TriggerEvent $event): void
    {
        ($this->connection)()->insert('core_audit_event', [
            'occurred_at' => $event->occurredAt->format('Y-m-d H:i:s'),
            'event' => $event->name,
            'actor_id' => $event->actorId,
            'company_id' => $event->companyId,
            'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
        ]);
    }
}
