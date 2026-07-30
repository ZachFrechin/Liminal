<?php

declare(strict_types=1);

namespace Liminal\Registry;

/**
 * Collects the menu items modules contribute, served ordered by priority.
 *
 * Deliberately the one registry without duplicate detection: a menu item has no
 * natural key — two modules may legitimately label entries alike under
 * different parents — and rendering collisions are a presentation concern, not
 * a boot invariant.
 */
final class MenuRegistry extends AbstractRegistry
{
    /** @var list<MenuItem> */
    private array $items = [];

    public function add(MenuItem $item): void
    {
        $this->assertMutable();

        $this->items[] = $item;
    }

    /**
     * Whether any item points at this route name. Lets a consumer tell a
     * parent that was filtered out from one that was never contributed —
     * the difference between hiding a subtree and a wiring mistake.
     */
    public function has(string $route): bool
    {
        foreach ($this->items as $item) {
            if ($item->route === $route) {
                return true;
            }
        }

        return false;
    }

    /** @return list<MenuItem> ordered by ascending priority */
    public function all(): array
    {
        return $this->isFrozen() ? $this->items : $this->sorted($this->items);
    }

    protected function onFreeze(): void
    {
        $this->items = $this->sorted($this->items);
    }

    /**
     * @param list<MenuItem> $items
     *
     * @return list<MenuItem>
     */
    private function sorted(array $items): array
    {
        usort($items, static fn(MenuItem $a, MenuItem $b): int => $a->priority <=> $b->priority);

        return $items;
    }
}
