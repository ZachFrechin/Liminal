<?php

declare(strict_types=1);

namespace Liminal\Registry\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * A contribution arrived after freeze(). The system's shape is fixed once boot
 * completes, so late mutation is a developer mistake, never a state to absorb.
 */
final class FrozenRegistryException extends LogicException implements LiminalException
{
    public static function for(string $registry): self
    {
        return new self(sprintf(
            'Registry "%s" is frozen: contributions are only accepted during kernel boot.',
            $registry,
        ));
    }
}
