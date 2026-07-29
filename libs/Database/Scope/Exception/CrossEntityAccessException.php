<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Scope\Exception;

use RuntimeException;

final class CrossEntityAccessException extends RuntimeException
{
    /**
     * @param list<int> $accessible
     */
    public static function for(string $class, ?int $entityId, array $accessible): self
    {
        return new self(sprintf(
            'Refusing to expose %s belonging to company %s; the current scope allows [%s].',
            $class,
            $entityId === null ? 'NULL' : (string) $entityId,
            implode(', ', $accessible),
        ));
    }
}
