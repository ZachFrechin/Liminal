<?php

declare(strict_types=1);

namespace Liminal\Registry;

final readonly class MenuItem
{
    public function __construct(
        public string $label,
        public string $route,
        public ?string $permission = null,
        public ?string $parent = null,
        public int $priority = 100,
    ) {}
}
