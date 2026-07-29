<?php

declare(strict_types=1);

namespace Liminal\Registry;

use Liminal\Registry\Contract\Module;
use Liminal\Registry\Exception\DuplicateContributionException;
use Liminal\Registry\Exception\UnknownModuleException;

/**
 * The declared module manifests, keyed by name in app.modules order.
 *
 * Kernel-owned and deliberately constructor-filled — the one registry with no
 * add(): a manifest added during contribute() would be one whose definitions()
 * never reached the (already built) container and whose own contribute() never
 * ran, a shape no docblock convention should have to police. The collection
 * still freezes it with the rest; there is simply nothing left to mutate.
 */
final class ModuleRegistry extends AbstractRegistry
{
    /** @var array<string, Module> */
    private array $modules = [];

    /**
     * @param list<Module> $modules
     *
     * @throws DuplicateContributionException when two manifests claim the same name
     */
    public function __construct(array $modules = [])
    {
        foreach ($modules as $module) {
            if (isset($this->modules[$module->name()])) {
                throw DuplicateContributionException::for(self::class, $module->name());
            }

            $this->modules[$module->name()] = $module;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    /**
     * @throws UnknownModuleException when no declared module carries the name
     */
    public function get(string $name): Module
    {
        return $this->modules[$name] ?? throw UnknownModuleException::for($name);
    }

    /** @return array<string, Module> keyed by name, in declaration order */
    public function all(): array
    {
        return $this->modules;
    }
}
