<?php

declare(strict_types=1);

namespace Liminal\Http;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

use Liminal\Registry\Route;
use Liminal\Registry\RouteRegistry;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds a FastRoute dispatcher from the RouteRegistry.
 *
 * The dispatcher is built lazily and once: the registry is frozen by then, so the
 * route table cannot change underneath it.
 */
final class Router
{
    private ?Dispatcher $dispatcher = null;

    public function __construct(private readonly RouteRegistry $routes) {}

    public function match(ServerRequestInterface $request): RouteMatch
    {
        $result = $this->dispatcher()->dispatch(
            $request->getMethod(),
            rawurldecode($request->getUri()->getPath()),
        );

        return match ($result[0]) {
            Dispatcher::FOUND => RouteMatch::found(
                self::asRoute($result[1]),
                self::asStringMap($result[2] ?? []),
            ),
            Dispatcher::METHOD_NOT_ALLOWED => RouteMatch::methodNotAllowed(self::asStringList($result[1] ?? [])),
            default => RouteMatch::notFound(),
        };
    }

    private function dispatcher(): Dispatcher
    {
        return $this->dispatcher ??= simpleDispatcher(function (RouteCollector $collector): void {
            foreach ($this->routes->all() as $route) {
                // The Route object itself is FastRoute's opaque data payload,
                // so the match carries every declared fact (handler, name,
                // public flag) — including through the HEAD->GET fallback.
                $collector->addRoute($route->method, $route->path, $route);
            }
        });
    }

    /**
     * @throws LogicException when the dispatcher hands back anything but a Route
     */
    private static function asRoute(mixed $value): Route
    {
        if (!$value instanceof Route) {
            // Routes enter the table as Route objects; anything else means the
            // route table is corrupted, and a silent fallback would surface as
            // a baffling error far from the cause.
            throw new LogicException(sprintf('FastRoute returned route data of type "%s".', get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function asStringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && (is_string($item) || is_int($item))) {
                $map[$key] = (string) $item;
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private static function asStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }
}
