<?php

declare(strict_types=1);

namespace Liminal\Registry;

use Liminal\Registry\Contract\Registry;
use Liminal\Registry\Exception\FrozenRegistryException;

abstract class AbstractRegistry implements Registry
{
    private bool $frozen = false;

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

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
