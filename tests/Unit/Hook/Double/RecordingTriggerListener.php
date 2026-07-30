<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Hook\Double;

use Liminal\Lib\Hook\Contract\TriggerListener;
use Liminal\Lib\Hook\TriggerEvent;

/**
 * Keeps every heard event in memory so tests can assert what was fired,
 * enriched with what, in which order.
 */
final class RecordingTriggerListener implements TriggerListener
{
    /** @var list<TriggerEvent> */
    public array $events = [];

    public function react(TriggerEvent $event): void
    {
        $this->events[] = $event;
    }
}
