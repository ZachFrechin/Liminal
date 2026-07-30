<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Fixtures\Kernel;

use Liminal\Lib\Hook\Contract\HookListener;
use Liminal\Lib\Hook\HookContext;

/**
 * First in the chain (priority 100): adds ten.
 */
final readonly class HookFixtureAddTenListener implements HookListener
{
    public function transform(mixed $value, HookContext $context): mixed
    {
        return (is_int($value) ? $value : 0) + 10;
    }
}
