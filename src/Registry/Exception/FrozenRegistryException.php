<?php

declare(strict_types=1);

namespace Liminal\Registry\Exception;

use LogicException;

final class FrozenRegistryException extends LogicException
{
    public static function for(string $registry): self
    {
        return new self(sprintf(
            'Registry %s is frozen: contributions are only accepted during kernel boot.',
            $registry,
        ));
    }
}
