<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * A security middleware found the pipeline in an impossible shape — always a
 * wiring mistake, never a request condition.
 */
final class AuthenticationException extends LogicException implements LiminalException
{
    public static function sessionMissing(string $middleware): self
    {
        return new self(sprintf('"%s" ran without a session; SessionMiddleware must run before it.', $middleware));
    }

    public static function routeMissing(string $middleware): self
    {
        return new self(sprintf('"%s" ran without a matched route; RouterMiddleware must run before it.', $middleware));
    }
}
