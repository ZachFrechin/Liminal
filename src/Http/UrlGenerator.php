<?php

declare(strict_types=1);

namespace Liminal\Http;

use Liminal\Http\Exception\UrlGenerationException;
use Liminal\Registry\RouteRegistry;
use LogicException;

/**
 * Turns a route name back into a path: substitutes {param} and {param:regex}
 * placeholders, url-encodes the values, and appends whatever parameters are
 * left as a query string. FastRoute has no reverse routing of its own, and
 * the frozen RouteRegistry is the single source of truth for names.
 */
final readonly class UrlGenerator
{
    /**
     * Mirrors FastRoute's placeholder grammar. The constraint part allows one
     * level of nested braces so regex quantifiers like {year:\d{4}} do not cut
     * the match short at their inner closing brace.
     */
    private const string PLACEHOLDER = '/\{\s*(\w+)\s*(?::[^{}]*(?:\{[^{}]*\}[^{}]*)*)?\}/';

    public function __construct(private RouteRegistry $routes) {}

    /**
     * @param array<string, string|int|float> $parameters
     *
     * @throws UrlGenerationException when the name is unknown, a placeholder has no value, or the path uses optional segments
     */
    public function generate(string $name, array $parameters = []): string
    {
        $route = $this->routes->named($name) ?? throw UrlGenerationException::unknownRoute($name);

        if (str_contains($route->path, '[')) {
            throw UrlGenerationException::optionalSegmentsUnsupported($name);
        }

        $remaining = $parameters;

        $path = preg_replace_callback(
            self::PLACEHOLDER,
            static function (array $match) use ($name, &$remaining): string {
                $parameter = $match[1];

                if (!array_key_exists($parameter, $remaining)) {
                    throw UrlGenerationException::missingParameter($name, $parameter);
                }

                $value = rawurlencode((string) $remaining[$parameter]);
                unset($remaining[$parameter]);

                return $value;
            },
            $route->path,
        );

        if (!is_string($path)) {
            // The pattern is a constant and valid; preg failure here is unreachable.
            throw new LogicException(sprintf('Placeholder substitution failed for route "%s".', $name));
        }

        return $remaining === [] ? $path : $path . '?' . http_build_query($remaining);
    }
}
