<?php

declare(strict_types=1);

namespace Liminal\Registry;

use InvalidArgumentException;

final readonly class Route
{
    /**
     * @param class-string|string $handler service id resolved through the container
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $handler,
        public ?string $name = null,
    ) {
        if ($this->path === '' || $this->path[0] !== '/') {
            throw new InvalidArgumentException(sprintf('Route path must start with "/", got "%s".', $this->path));
        }
    }
}
