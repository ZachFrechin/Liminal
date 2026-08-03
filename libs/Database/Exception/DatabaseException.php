<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Exception;

use Liminal\Exception\LiminalException;
use RuntimeException;

/**
 * Assembly failures of the database lib itself — as opposed to the scope
 * violations, which have their own dedicated classes.
 */
final class DatabaseException extends RuntimeException implements LiminalException
{
    public static function noEntityNamespaces(): self
    {
        return new self('No entity namespace was contributed; the EntityManager would have no mapping.');
    }

    public static function unexpectedConnectionType(): self
    {
        return new self('Container returned an unexpected type for the database connection.');
    }

    public static function unsafeSequenceTable(string $table): self
    {
        return new self(sprintf(
            'Sequence table name "%s" is not a bare lowercase identifier — table names come from module constants, never from input.',
            $table,
        ));
    }

    public static function unknownDefaultSort(string $key): self
    {
        return new self(sprintf(
            'List schema defaults to sort key "%s", which it does not declare as sortable.',
            $key,
        ));
    }

    public static function nonPositivePageSize(int $perPage): self
    {
        return new self(sprintf('List schema declares a page size of %d; a page holds at least one row.', $perPage));
    }

    public static function misfiledFilter(string $index, string $key): self
    {
        return new self(sprintf(
            'List filter "%s" is indexed under "%s" — the request reads filters by their own key, so the two must agree.',
            $key,
            $index,
        ));
    }

    public static function emptyFilterPredicate(string $filter, string $value): self
    {
        return new self(sprintf(
            'Filter "%s" maps value "%s" to an empty predicate; a choice that narrows nothing is a silent no-op.',
            $filter,
            $value,
        ));
    }

    public static function unexpectedListResult(string $entity): self
    {
        return new self(sprintf('Paginated query returned a row that is not a %s.', $entity));
    }
}
