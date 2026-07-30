<?php

declare(strict_types=1);

namespace Liminal\Lib\Rendering\Menu;

use Liminal\Registry\MenuItem;

/**
 * One visible menu entry with its visible children — the shape templates walk
 * recursively, after every filter has run.
 */
final readonly class MenuNode
{
    /**
     * @param list<MenuNode> $children
     */
    public function __construct(
        public MenuItem $item,
        public array $children,
    ) {}
}
