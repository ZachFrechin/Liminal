<?php

declare(strict_types=1);

namespace Liminal\Lib\Rendering\Menu;

use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Module\ModuleManager;
use Liminal\Lib\Rendering\Exception\RenderingException;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Lib\Security\Authorization\Gate;
use Liminal\Registry\MenuItem;
use Liminal\Registry\MenuRegistry;
use Liminal\Registry\ModuleRegistry;

/**
 * Turns the frozen menu contributions into the tree this request may see.
 *
 * Three filters, in this order: anonymous requests get nothing at all (which
 * also keeps public pages renderable with no database in reach — the property
 * the session manager protects); an item whose route belongs to a module
 * disabled for the current company disappears; an item naming a permission the
 * gate denies disappears. A hidden parent hides its whole subtree — anything
 * else would leak a disabled module's shape through its children.
 */
final readonly class MenuBuilder
{
    public function __construct(
        private MenuRegistry $menu,
        private ModuleRegistry $modules,
        private ModuleManager $manager,
        private Gate $gate,
        private CurrentUser $currentUser,
        private CompanyContext $context,
    ) {}

    /**
     * @return list<MenuNode>
     *
     * @throws RenderingException when an item's parent names a route no item declares
     */
    public function build(): array
    {
        if ($this->currentUser->get() === null) {
            // Before any database work: an anonymous page has no menu, and a
            // DSN-less checkout must still serve its public pages.
            return [];
        }

        $enabled = $this->manager->enabledFor($this->context->currentId());
        $visible = [];

        foreach ($this->menu->all() as $item) {
            if ($this->isVisible($item, $enabled)) {
                $visible[$item->route] = $item;
            }
        }

        return $this->tree($visible);
    }

    /**
     * @param array<string, bool> $enabled
     */
    private function isVisible(MenuItem $item, array $enabled): bool
    {
        $module = $this->moduleOf($item->route);

        if ($module !== null && ($enabled[$module] ?? false) === false) {
            return false;
        }

        return $item->permission === null || $this->gate->allows($item->permission);
    }

    /**
     * Mirrors the module gate's own rule: the prefix before the first dot,
     * gated only when it names a declared module (lib routes pass untouched).
     */
    private function moduleOf(string $routeName): ?string
    {
        $separator = strpos($routeName, '.');

        if ($separator === false) {
            return null;
        }

        $prefix = substr($routeName, 0, $separator);

        return $this->modules->has($prefix) ? $prefix : null;
    }

    /**
     * @param array<string, MenuItem> $visible
     *
     * @return list<MenuNode>
     *
     * @throws RenderingException
     */
    private function tree(array $visible): array
    {
        $roots = [];

        foreach ($visible as $item) {
            if ($item->parent === null) {
                $roots[] = $item;

                continue;
            }

            if (!$this->menu->has($item->parent)) {
                throw RenderingException::orphanedMenuParent($item->label, $item->parent);
            }

            // A parent filtered out of $visible takes its children with it.
        }

        return array_map(fn(MenuItem $item): MenuNode => $this->node($item, $visible), $roots);
    }

    /**
     * @param array<string, MenuItem> $visible
     */
    private function node(MenuItem $item, array $visible): MenuNode
    {
        $children = [];

        foreach ($visible as $candidate) {
            if ($candidate->parent === $item->route) {
                $children[] = $this->node($candidate, $visible);
            }
        }

        return new MenuNode($item, $children);
    }
}
