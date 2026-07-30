<?php

declare(strict_types=1);

namespace Liminal\Lib\Hook\Contract;

use Liminal\Lib\Hook\HookContext;

/**
 * A synchronous, in-flow transformation step: receives the travelling value,
 * returns its replacement. Exceptions PROPAGATE — a hook is business logic,
 * and a failing step must abort the computation it is part of.
 *
 * A hook listener may itself filter through other hooks: that is
 * composition, and the propagation rule keeps it honest.
 */
interface HookListener
{
    public function transform(mixed $value, HookContext $context): mixed;
}
