<?php

declare(strict_types=1);

namespace Liminal\Lib\Hook;

use DateTimeImmutable;

/**
 * The immutable fact a trigger announces: what happened, to what, on whose
 * behalf, in which company, when.
 *
 * The payload carries identifying scalars only — ids, codes, emails — and
 * NEVER a secret: it lands verbatim in the audit trail. companyId is the
 * company the event BELONGS to: the working context at fire time unless the
 * fire point knew better (console commands pass their --company).
 */
final readonly class TriggerEvent
{
    /**
     * @param array<string, scalar|null> $payload
     */
    public function __construct(
        public string $name,
        public array $payload,
        public ?int $actorId,
        public int $companyId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
