<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Fixtures\Kernel;

use Liminal\Lib\Hook\Contract\HookListener;
use Liminal\Lib\Hook\HookContext;

/**
 * Second in the chain (priority 200): doubles what the first produced.
 */
final readonly class HookFixtureDoubleListener implements HookListener
{
    public function transform(mixed $value, HookContext $context): mixed
    {
        return (is_int($value) ? $value : 0) * 2;
    }
}
