<?php

declare(strict_types=1);

namespace Liminal\Http\Exception;

use Liminal\Exception\LiminalException;
use LogicException;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The dispatcher could not turn the matched route into a runnable handler —
 * either no route match reached it, or the resolved service is not a request
 * handler. Both are wiring mistakes, not request conditions.
 */
final class InvalidHandlerException extends LogicException implements LiminalException
{
    public static function missingRouteMatch(): self
    {
        return new self('DispatchMiddleware ran without a resolved route; RouterMiddleware must run before it.');
    }

    public static function notARequestHandler(string $handler): self
    {
        return new self(sprintf('Handler "%s" must implement %s.', $handler, RequestHandlerInterface::class));
    }
}
