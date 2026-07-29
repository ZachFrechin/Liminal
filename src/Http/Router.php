<?php

declare(strict_types=1);

namespace Liminal\Http;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Liminal\Registry\RouteRegistry;
use Psr\Http\Message\ServerRequestInterface;

use function FastRoute\simpleDispatcher;

/**
 * Builds a FastRoute dispatcher from the RouteRegistry.
 *
 * The dispatcher is built lazily and once: the registry is frozen by then, so the
 * route table cannot change underneath it.
 */
final class Router
{
    private ?Dispatcher $dispatcher = null;

    public function __construct(private readonly RouteRegistry $routes)
    {
    }

    public function match(ServerRequestInterface $request): RouteMatch
    {
        $result = $this->dispatcher()->dispatch(
            $request->getMethod(),
            rawurldecode($request->getUri()->getPath()),
        );

        return match ($result[0]) {
            Dispatcher::FOUND => RouteMatch::found(
                self::asString($result[1]),
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
                $collector->addRoute($route->method, $route->path, $route->handler);
            }
        });
    }

    private static function asString(mixed $value): string
    {
        return is_string($value) ? $value : '';
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
