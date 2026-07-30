<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * The module's own wiring failures. Data conditions the screens surface
 * (duplicate code, validation) are flashed to the operator rather than
 * thrown, so they do not belong here.
 */
final class ThirdpartyModuleException extends LogicException implements LiminalException
{
    public static function sessionMissing(): self
    {
        return new self('No session is attached to the request: the session middleware did not run.');
    }

    public static function malformedVeto(string $type): self
    {
        return new self(sprintf(
            'A thirdparty.deletion.veto listener returned %s instead of a list of catalogue keys — the declarer validates the shape, and this one is wiring.',
            $type,
        ));
    }
}
