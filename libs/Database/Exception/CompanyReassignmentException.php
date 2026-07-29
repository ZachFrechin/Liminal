<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Exception;

use Liminal\Exception\LiminalException;
use RuntimeException;

/**
 * A flush tried to change the company id of an already-persisted entity.
 *
 * Distinct from CrossCompanyAccessException on purpose: this is not "outside
 * your scope" but "the scope is immutable" — refused even when the target
 * company is accessible, because a silent flip is indistinguishable from an
 * exfiltration. Moving rows between companies belongs to a future audited
 * administrative service working at the DBAL level, not to the ORM.
 */
final class CompanyReassignmentException extends RuntimeException implements LiminalException
{
    public static function for(string $class, mixed $from, mixed $to): self
    {
        return new self(sprintf(
            'Refusing to flush "%s": company_id is write-once and may not change from %s to %s.',
            $class,
            self::describe($from),
            self::describe($to),
        ));
    }

    private static function describe(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return is_scalar($value) ? var_export($value, true) : get_debug_type($value);
    }
}
