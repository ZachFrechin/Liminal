<?php

declare(strict_types=1);

namespace Liminal\Lib\Hook\Contract;

/**
 * Where a fired trigger belongs: the acting user and the working company.
 *
 * A contract rather than direct holder dependencies, because the holders
 * live in the Security and Database libs — and this lib must not depend on
 * its own consumers (Security implements TriggerListener for the audit).
 * The lib ships an inert default; the security lib overrides it through the
 * same last-wins definition layering everything else uses.
 */
interface TriggerScope
{
    public function actorId(): ?int;

    public function companyId(): int;
}
