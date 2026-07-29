<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * A permission was checked without ever being declared. Checks are only legal
 * against the PermissionRegistry — the same boundary undeclared settings have.
 */
final class UndeclaredPermissionException extends LogicException implements LiminalException
{
    public static function for(string $permission): self
    {
        return new self(sprintf('Permission "%s" was never declared in the PermissionRegistry.', $permission));
    }
}
