<?php

declare(strict_types=1);

namespace Liminal\Http;

/**
 * Outcome of routing one request: a status plus whatever that status carries —
 * handler and arguments when found, the allowed methods on a 405, nothing on
 * a 404. Built through named constructors so impossible combinations cannot
 * exist.
 */
final readonly class RouteMatch
{
    /**
     * @param array<string, string> $arguments
     * @param list<string>          $allowedMethods
     */
    private function __construct(
        public RouteMatchStatus $status,
        public string $handler = '',
        public array $arguments = [],
        public array $allowedMethods = [],
    ) {}

    /**
     * @param array<string, string> $arguments
     */
    public static function found(string $handler, array $arguments): self
    {
        return new self(RouteMatchStatus::Found, $handler, $arguments);
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
