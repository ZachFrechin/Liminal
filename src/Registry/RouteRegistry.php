<?php

declare(strict_types=1);

namespace Liminal\Registry;

final class RouteRegistry extends AbstractRegistry
{
    /** @var list<Route> */
    private array $routes = [];

    public function add(Route $route): void
    {
        $this->assertMutable();

        $this->routes[] = $route;
    }

    /**
     * @param class-string|string $handler
     */
    public function get(string $path, string $handler, ?string $name = null): void
    {
        $this->add(new Route('GET', $path, $handler, $name));
    }

    /**
     * @param class-string|string $handler
     */
    public function post(string $path, string $handler, ?string $name = null): void
    {
        $this->add(new Route('POST', $path, $handler, $name));
    }

    /** @return list<Route> */
    public function all(): array
    {
        return $this->routes;
    }
}
