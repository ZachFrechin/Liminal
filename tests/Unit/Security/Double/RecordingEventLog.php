<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Security\Double;

use Liminal\Lib\Security\Authentication\AuthEvent;
use Liminal\Lib\Security\Authentication\ClientContext;
use Liminal\Lib\Security\Contract\AuthEventLog;

/**
 * Keeps the audit trail in memory so tests can assert that every outcome
 * records exactly one event.
 */
final class RecordingEventLog implements AuthEventLog
{
    /** @var list<AuthEvent> */
    public array $events = [];

    public function record(
        AuthEvent $event,
        string $identifier,
        ?int $userId,
        ClientContext $client,
    ): void {
        $this->events[] = $event;
    }
}
