<?php

declare(strict_types=1);

namespace Liminal\Http\Exception;

use LogicException;

/**
 * The middleware list ran out before any middleware produced a response: the
 * pipeline was assembled without a terminal middleware, which is a
 * construction mistake, not a request condition.
 */
final class EmptyPipelineException extends LogicException
{
    public static function exhausted(): self
    {
        return new self('The middleware pipeline was exhausted without producing a response.');
    }
}
