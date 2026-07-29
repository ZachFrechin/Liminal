<?php

declare(strict_types=1);

namespace Liminal\Registry;

/**
 * One navigation entry a module contributes. parent names another item.s route,
 * permission will gate visibility once phase 3 lands, and priority orders
 * siblings ascending.
 */
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
