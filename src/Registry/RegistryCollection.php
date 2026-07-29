<?php

declare(strict_types=1);

namespace Liminal\Registry;

use Liminal\Registry\Contract\Registry;
use Liminal\Registry\Exception\UnknownRegistryException;

/**
 * Type-safe container of every registry in the system.
 *
 * Freezing cascades to every registered registry, so a single call at the end of
 * boot closes the whole extension surface at once.
 */
final class RegistryCollection
{
    /** @var array<class-string<Registry>, Registry> */
    private array $registries = [];

    private bool $frozen = false;

    /**
     * @param iterable<Registry> $registries
     */
    public function __construct(iterable $registries = [])
    {
        foreach ($registries as $registry) {
            $this->register($registry);
        }
    }

    public function register(Registry $registry): void
    {
        $this->registries[$registry::class] = $registry;
    }

    /**
     * @template T of Registry
     *
     * @param class-string<T> $class
     *
     * @return T
     *
     * @throws UnknownRegistryException when no registry of that class was registered
     */
    public function get(string $class): Registry
    {
        $registry = $this->registries[$class] ?? null;

        if (!$registry instanceof $class) {
            throw UnknownRegistryException::for($class);
        }

        return $registry;
    }

    /**
     * @param class-string<Registry> $class
     */
    public function has(string $class): bool
    {
        return isset($this->registries[$class]);
    }

    /**
     * Close the extension surface. Idempotent.
     */
    public function freeze(): void
    {
        foreach ($this->registries as $registry) {
            $registry->freeze();
        }

        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }
}
