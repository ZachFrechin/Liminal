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

    /**
     * The service-level guard behind the screen's polite refusal: whatever the
     * UI forgets, deleting the role the bootstrap command anchors on is a
     * programming error, not a data condition.
     */
    public static function protectedRole(string $code): self
    {
        return new self(sprintf('The "%s" role is protected and cannot be deleted.', $code));
    }
}
