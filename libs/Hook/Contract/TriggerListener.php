<?php

declare(strict_types=1);

namespace Liminal\Lib\Hook\Contract;

use Liminal\Lib\Hook\TriggerEvent;

/**
 * A post-commit reaction: hears that something happened, changes nothing
 * about it. Exceptions are CAUGHT AND LOGGED by the dispatcher — the event
 * already happened, and no listener failure may unhappen it or break the
 * response that carried it.
 *
 * A trigger listener must NOT fire triggers: that is unbounded recursion
 * with no guard, and v1 chooses a written rule over a depth counter.
 */
interface TriggerListener
{
    public function react(TriggerEvent $event): void;
}
