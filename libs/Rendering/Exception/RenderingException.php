<?php

declare(strict_types=1);

namespace Liminal\Lib\Rendering\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * A view rendered against broken wiring. Content problems (a missing
 * translation, an empty flash) degrade gracefully; wiring problems fail loud
 * — this is the loud half.
 */
final class RenderingException extends LogicException implements LiminalException
{
    public static function sessionUnavailable(string $function): self
    {
        return new self(sprintf(
            'The "%s" function needs a session, and no ViewContext session is assigned: the rendering middleware did not run for this request.',
            $function,
        ));
    }
}
