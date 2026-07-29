<?php

declare(strict_types=1);

namespace Liminal\Registry;

use InvalidArgumentException;

/**
 * One HTTP route as a contributor declared it. Validation happens here, at
 * construction, so a bad route names its contributor at boot instead of
 * failing inside FastRoute on the first matching request.
 */
final readonly class Route
{
    public const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * @param string $handler service id resolved through the container
     *
     * @throws InvalidArgumentException when the method is unknown or the path does not start with "/"
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $handler,
        public ?string $name = null,
    ) {
        if (!in_array($this->method, self::METHODS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Route method must be one of [%s], got "%s".',
                implode(', ', self::METHODS),
                $this->method,
            ));
        }

        if ($this->path === '' || $this->path[0] !== '/') {
            throw new InvalidArgumentException(sprintf('Route path must start with "/", got "%s".', $this->path));
        }
    }
}
