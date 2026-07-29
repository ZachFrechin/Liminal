<?php

declare(strict_types=1);

namespace Liminal\Registry;

use Liminal\Registry\Contract\Registry;
use Liminal\Registry\Exception\FrozenRegistryException;

/**
 * Base of every registry: owns the frozen flag and the mutation guard.
 *
 * freeze() is a template method — final so no registry can dodge its own
 * freezing — with onFreeze() as the hook where a registry normalises internal
 * state (pre-sorting, caching) one last time while still mutable.
 */
abstract class AbstractRegistry implements Registry
{
    private bool $frozen = false;

    final public function freeze(): void
    {
        if ($this->frozen) {
            return;
        }

        $this->onFreeze();
        $this->frozen = true;
    }

    final public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * Hook run exactly once, just before the frozen flag flips: the last
     * chance to normalise internal state while mutation is still allowed.
     */
    protected function onFreeze(): void {}

    /**
     * @throws FrozenRegistryException when called after boot has completed
     */
    final protected function assertMutable(): void
    {
        if ($this->frozen) {
            throw FrozenRegistryException::for(static::class);
        }
    }
}
