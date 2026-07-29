<?php

declare(strict_types=1);

namespace Liminal\Registry;

use Liminal\Registry\Exception\DuplicateContributionException;

/**
 * Maps a migration namespace to the directory holding its migration classes.
 *
 * The namespace doubles as the scoping key used by the ScopedPlanCalculator so a
 * module's migrations can be run without dragging every other namespace's pending
 * migrations along with them.
 */
final class MigrationRegistry extends AbstractRegistry
{
    /** @var array<string, string> */
    private array $namespaces = [];

    /**
     * @param non-empty-string $namespace
     *
     * @throws DuplicateContributionException when the namespace is already mapped
     */
    public function add(string $namespace, string $directory): void
    {
        $this->assertMutable();

        $namespace = trim($namespace, '\\');

        if (isset($this->namespaces[$namespace])) {
            throw DuplicateContributionException::for(static::class, $namespace);
        }

        $this->namespaces[$namespace] = $directory;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->namespaces;
    }
}
