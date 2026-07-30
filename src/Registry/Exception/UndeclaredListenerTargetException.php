<?php

declare(strict_types=1);

namespace Liminal\Registry\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * A listener subscribed to a hook or trigger name nobody declares. Raised at
 * freeze time, not at listen() time, so a consumer may subscribe to a name
 * whose declaring module contributes later in app.modules order — the check
 * is order-blind and the boot still refuses a name that never arrives.
 */
final class UndeclaredListenerTargetException extends LogicException implements LiminalException
{
    public static function for(string $registry, string $name, string $listener): self
    {
        return new self(sprintf(
            '%s: listener "%s" subscribed to "%s", which no contributor declares.',
            $registry,
            $listener,
            $name,
        ));
    }
}
