<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Exception;

use Liminal\Exception\LiminalException;
use RuntimeException;

/**
 * A row belonging to a company outside the current scope reached — or tried to
 * enter — the ORM. Raised by the postLoad guard, the persist validation and
 * the flush-time insert re-check alike.
 */
final class CrossCompanyAccessException extends RuntimeException implements LiminalException
{
    /**
     * @param list<int> $accessible
     */
    public static function for(string $class, ?int $companyId, array $accessible): self
    {
        return new self(sprintf(
            'Refusing to expose "%s" belonging to company %s; the current scope allows [%s].',
            $class,
            $companyId === null ? 'NULL' : (string) $companyId,
            implode(', ', $accessible),
        ));
    }
}
