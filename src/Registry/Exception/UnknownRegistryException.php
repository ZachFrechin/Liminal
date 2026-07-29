<?php

declare(strict_types=1);

namespace Liminal\Registry\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * A lookup asked the collection for a registry class nobody registered —
 * usually a consumer relying on a registry its dependency never contributed.
 */
final class UnknownRegistryException extends LogicException implements LiminalException
{
    public static function for(string $registry): self
    {
        return new self(sprintf('No registry of type "%s" is available.', $registry));
    }
}
