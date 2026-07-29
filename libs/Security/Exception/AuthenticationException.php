<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * The authentication middleware found the pipeline in an impossible shape —
 * always a wiring mistake, never a request condition.
 */
final class AuthenticationException extends LogicException implements LiminalException
{
    public static function sessionMissing(): self
    {
        return new self('AuthenticationMiddleware ran without a session; SessionMiddleware must run before it.');
    }

    public static function routeMissing(): self
    {
        return new self('AuthenticationMiddleware ran without a matched route; RouterMiddleware must run before it.');
    }
}
