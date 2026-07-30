<?php

declare(strict_types=1);

namespace Liminal\Registry;

use InvalidArgumentException;
use Liminal\Registry\Exception\DuplicateContributionException;

/**
 * Maps Twig namespaces (@liminal, @<module>) to template directories.
 *
 * Twig's FilesystemLoader tries a namespace's paths in order and the FIRST
 * existing template wins — the opposite of the container's last-wins layering.
 * all() therefore serves each namespace's paths latest-contribution-first,
 * reversed in onFreeze(), so a module's template shadows a lib's under the
 * same namespace. The reversal lives here so no consumer can forget it.
 */
final class TemplateRegistry extends AbstractRegistry
{
    /** @var array<string, list<string>> */
    private array $namespaces = [];

    /**
     * Paths accumulate under one namespace across calls; only the exact same
     * namespace+path pair is a refused duplicate.
     *
     * @param non-empty-string $namespace a Twig namespace slug, without the "@"
     *
     * @throws InvalidArgumentException       when the namespace is not a slug or no path is given
     * @throws DuplicateContributionException when a namespace+path pair is contributed twice
     */
    public function add(string $namespace, string ...$paths): void
    {
        $this->assertMutable();

        if (preg_match('/^[a-z][a-z0-9_]*$/', $namespace) !== 1) {
            // Twig would accept almost anything here and only explode at the
            // first render; a slug rule keeps namespaces predictable.
            throw new InvalidArgumentException(sprintf(
                'Template namespace "%s" must be a lowercase slug (a-z, 0-9, _).',
                $namespace,
            ));
        }

        if ($paths === []) {
            throw new InvalidArgumentException(sprintf('Template namespace "%s" needs at least one path.', $namespace));
        }

        foreach ($paths as $path) {
            if (in_array($path, $this->namespaces[$namespace] ?? [], true)) {
                throw DuplicateContributionException::for(static::class, $namespace . ' => ' . $path);
            }

            $this->namespaces[$namespace][] = $path;
        }
    }

    /**
     * @return array<string, list<string>> paths in Twig lookup order (highest precedence first)
     */
    public function all(): array
    {
        return $this->isFrozen() ? $this->namespaces : $this->reversed($this->namespaces);
    }

    protected function onFreeze(): void
    {
        $this->namespaces = $this->reversed($this->namespaces);
    }

    /**
     * @param array<string, list<string>> $namespaces
     *
     * @return array<string, list<string>>
     */
    private function reversed(array $namespaces): array
    {
        return array_map(array_reverse(...), $namespaces);
    }
}
