<?php

declare(strict_types=1);

namespace Liminal\Registry;

final class MenuRegistry extends AbstractRegistry
{
    /** @var list<MenuItem> */
    private array $items = [];

    public function add(MenuItem $item): void
    {
        $this->assertMutable();

        $this->items[] = $item;
    }

    /** @return list<MenuItem> ordered by ascending priority */
    public function all(): array
    {
        $items = $this->items;

        usort($items, static fn(MenuItem $a, MenuItem $b): int => $a->priority <=> $b->priority);

        return $items;
    }
}
