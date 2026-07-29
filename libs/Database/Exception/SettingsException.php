<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * A setting was used in a way its declaration does not (yet) allow.
 */
final class SettingsException extends LogicException implements LiminalException
{
    public static function userScopeNotAvailable(): self
    {
        return new self('User-scoped settings arrive with authentication (phase 3).');
    }
}
