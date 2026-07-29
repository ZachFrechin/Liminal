<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Exception;

use RuntimeException;

final class CrossCompanyAccessException extends RuntimeException
{
    /**
     * @param list<int> $accessible
     */
    public static function for(string $class, ?int $companyId, array $accessible): self
    {
        return new self(sprintf(
            'Refusing to expose %s belonging to company %s; the current scope allows [%s].',
            $class,
            $companyId === null ? 'NULL' : (string) $companyId,
            implode(', ', $accessible),
        ));
    }
}
