<?php

declare(strict_types=1);

namespace Liminal\Http;

use Liminal\Registry\Route;

/**
 * Outcome of routing one request: a status plus whatever that status carries —
 * the matched Route and its arguments when found, the allowed methods on a
 * 405, nothing on a 404. Built through named constructors so impossible
 * combinations cannot exist.
 *
 * Carrying the whole Route (not just the handler string) is what lets the
 * authentication middleware read the declared public flag without a second
 * lookup: the route table stays the single source of truth.
 */
final readonly class RouteMatch
{
    /**
     * @param array<string, string> $arguments
     * @param list<string>          $allowedMethods
     */
    private function __construct(
        public RouteMatchStatus $status,
        public ?Route $route = null,
        public array $arguments = [],
        public array $allowedMethods = [],
    ) {}

    /**
     * @param array<string, string> $arguments
     */
    public static function found(Route $route, array $arguments): self
    {
        return new self(RouteMatchStatus::Found, $route, $arguments);
    }

    public static function notFound(): self
    {
        return new self(RouteMatchStatus::NotFound);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(RouteMatchStatus::MethodNotAllowed, allowedMethods: $allowedMethods);
    }
}
