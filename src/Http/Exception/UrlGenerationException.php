<?php

declare(strict_types=1);

namespace Liminal\Http\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * URL generation was asked for something the route table cannot honour —
 * always a developer mistake: a mistyped route name, a forgotten parameter,
 * or a syntax the generator does not cover yet.
 */
final class UrlGenerationException extends LogicException implements LiminalException
{
    public static function unknownRoute(string $name): self
    {
        return new self(sprintf('No route is named "%s".', $name));
    }

    public static function missingParameter(string $route, string $parameter): self
    {
        return new self(sprintf('Route "%s" needs a value for parameter "%s".', $route, $parameter));
    }

    public static function optionalSegmentsUnsupported(string $name): self
    {
        return new self(sprintf(
            'Route "%s" uses FastRoute optional segments, which URL generation does not support yet.',
            $name,
        ));
    }
}
