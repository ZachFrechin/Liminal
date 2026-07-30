<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Hook\Double;

use Liminal\Lib\Hook\Contract\TriggerListener;
use Liminal\Lib\Hook\TriggerEvent;
use RuntimeException;

/**
 * Always explodes — the listener whose failure must never reach the caller.
 */
final class FailingTriggerListener implements TriggerListener
{
    public function react(TriggerEvent $event): void
    {
        throw new RuntimeException('listener exploded');
    }
}
