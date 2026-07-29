<?php

declare(strict_types=1);

namespace Liminal\Registry\Exception;

use LogicException;

final class UnknownRegistryException extends LogicException
{
    public static function for(string $registry): self
    {
        return new self(sprintf('No registry of type %s is available.', $registry));
    }
}
