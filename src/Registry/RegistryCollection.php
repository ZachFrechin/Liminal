<?php

declare(strict_types=1);

namespace Liminal\Registry;

use Liminal\Registry\Contract\Registry;
use Liminal\Registry\Exception\DuplicateContributionException;
use Liminal\Registry\Exception\FrozenRegistryException;
use Liminal\Registry\Exception\UnknownRegistryException;

/**
 * Type-safe container of every registry in the system.
 *
 * Freezing cascades to every registered registry, so a single call at the end
 * of boot closes the whole extension surface at once — including this
 * collection itself: no new registry may appear after freeze, or the "shape is
 * fixed" guarantee would be a fiction.
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

    /**
     * @throws FrozenRegistryException       when called after boot has completed
     * @throws DuplicateContributionException when a registry of that class already exists
     */
    public function register(Registry $registry): void
    {
        if ($this->frozen) {
            throw FrozenRegistryException::for(self::class);
        }

        if (isset($this->registries[$registry::class])) {
            throw DuplicateContributionException::for(self::class, $registry::class);
        }

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

    /** @return list<Registry> */
    public function all(): array
    {
        return array_values($this->registries);
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
