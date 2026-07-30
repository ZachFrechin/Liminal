<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * The module's own wiring failures. Data conditions the commands surface
 * (duplicate email, unknown role) are reported to the operator rather than
 * thrown, so they do not belong here.
 */
final class AuthenticationModuleException extends LogicException implements LiminalException
{
    public static function sessionMissing(): self
    {
        return new self('No session is attached to the request: the session middleware did not run.');
    }
}
