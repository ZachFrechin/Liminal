<?php

declare(strict_types=1);

namespace Liminal\Registry;

use InvalidArgumentException;
use Liminal\Registry\Exception\DuplicateContributionException;

/**
 * Maps Doctrine entity namespaces to the directories holding their classes.
 *
 * The ordering guarantee below is load-bearing, not cosmetic. Doctrine's
 * MappingDriverChain resolves a class by iterating its drivers and returning the
 * FIRST whose namespace prefix matches (str_starts_with). With
 * "Liminal\Module\Stock" registered before "Liminal\Module\StockAdvanced", every
 * StockAdvanced entity would silently be handed to the Stock driver and fail to
 * resolve. Sorting by descending namespace length makes the longest — i.e. most
 * specific — prefix win regardless of contribution order.
 *
 * The sort lives here rather than in the consumer so that no caller can forget
 * it; freezing materialises it once via onFreeze().
 */
final class EntityRegistry extends AbstractRegistry
{
    /** @var array<string, list<string>> */
    private array $namespaces = [];

    /**
     * Paths accumulate under one namespace across calls; only the exact same
     * namespace+path pair is a refused duplicate.
     *
     * @param non-empty-string $namespace
     *
     * @throws InvalidArgumentException       when no path is given
     * @throws DuplicateContributionException when a namespace+path pair is contributed twice
     */
    public function add(string $namespace, string ...$paths): void
    {
        $this->assertMutable();

        if ($paths === []) {
            throw new InvalidArgumentException(sprintf('Entity namespace "%s" needs at least one path.', $namespace));
        }

        $namespace = trim($namespace, '\\');

        foreach ($paths as $path) {
            if (in_array($path, $this->namespaces[$namespace] ?? [], true)) {
                throw DuplicateContributionException::for(static::class, $namespace . ' => ' . $path);
            }

            $this->namespaces[$namespace][] = $path;
        }
    }

    /**
     * @return array<string, list<string>> namespaces ordered from most to least specific
     */
    public function all(): array
    {
        return $this->isFrozen() ? $this->namespaces : $this->sorted($this->namespaces);
    }

    protected function onFreeze(): void
    {
        $this->namespaces = $this->sorted($this->namespaces);
    }

    /**
     * @param array<string, list<string>> $namespaces
     *
     * @return array<string, list<string>>
     */
    private function sorted(array $namespaces): array
    {
        uksort($namespaces, static fn(string $a, string $b): int => strlen($b) <=> strlen($a) ?: strcmp($a, $b));

        return $namespaces;
    }
}
