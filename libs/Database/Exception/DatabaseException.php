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
}
