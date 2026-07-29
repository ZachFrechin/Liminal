<?php

declare(strict_types=1);

namespace Liminal\Registry;

use Liminal\Registry\Exception\DuplicateContributionException;

/**
 * Collects every HTTP route contributed during boot; the Router builds its
 * dispatcher from all() once the registry is frozen.
 *
 * Duplicates are refused here, at contribution time, rather than surfacing as a
 * FastRoute error on the first matching request: a boot-time failure names the
 * offending route while the contributor is still on the stack.
 */
final class RouteRegistry extends AbstractRegistry
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, true> */
    private array $identities = [];

    /** @var array<string, Route> */
    private array $names = [];

    /**
     * @throws DuplicateContributionException when the method+path pair or the route name is already taken
     */
    public function add(Route $route): void
    {
        $this->assertMutable();

        $identity = $route->method . ' ' . $route->path;

        if (isset($this->identities[$identity])) {
            throw DuplicateContributionException::for(static::class, $identity);
        }

        if ($route->name !== null && isset($this->names[$route->name])) {
            throw DuplicateContributionException::for(static::class, $route->name);
        }

        $this->identities[$identity] = true;

        if ($route->name !== null) {
            $this->names[$route->name] = $route;
        }

        $this->routes[] = $route;
    }

    /**
     * Lookup for URL generation; null rather than throwing so the caller owns
     * the error wording (UrlGenerator names both the route and its purpose).
     */
    public function named(string $name): ?Route
    {
        return $this->names[$name] ?? null;
    }

    /**
     * Generic form for the rarer verbs (HEAD, OPTIONS); the named helpers
     * below cover the common ones.
     *
     * @param string $handler service id resolved through the container
     */
    public function map(string $method, string $path, string $handler, ?string $name = null): void
    {
        $this->add(new Route($method, $path, $handler, $name));
    }

    /**
     * @param string $handler service id resolved through the container
     */
    public function get(string $path, string $handler, ?string $name = null): void
    {
        $this->map('GET', $path, $handler, $name);
    }

    /**
     * @param string $handler service id resolved through the container
     */
    public function post(string $path, string $handler, ?string $name = null): void
    {
        $this->map('POST', $path, $handler, $name);
    }

    /**
     * @param string $handler service id resolved through the container
     */
    public function put(string $path, string $handler, ?string $name = null): void
    {
        $this->map('PUT', $path, $handler, $name);
    }

    /**
     * @param string $handler service id resolved through the container
     */
    public function patch(string $path, string $handler, ?string $name = null): void
    {
        $this->map('PATCH', $path, $handler, $name);
    }

    /**
     * @param string $handler service id resolved through the container
     */
    public function delete(string $path, string $handler, ?string $name = null): void
    {
        $this->map('DELETE', $path, $handler, $name);
    }

    /** @return list<Route> */
    public function all(): array
    {
        return $this->routes;
    }
}
