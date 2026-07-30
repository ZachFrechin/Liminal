<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Migration;

use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;
use Liminal\Registry\MigrationRegistry;

/**
 * Orders migrations by the contribution order of their namespace, then by class
 * name within it.
 *
 * Doctrine's default comparator compares fully-qualified class names, which
 * makes cross-namespace ordering an accident of spelling: it works here only
 * because "Liminal\Lib\…" happens to sort before "Liminal\Module\…" on one
 * byte. A third-party module ("Acme\Erp\…") would sort before the core
 * namespaces and its migration would run against a database whose core tables
 * do not exist yet.
 *
 * The MigrationRegistry already carries the right order — libs in app.libs
 * order, then modules in app.modules order — so rank by it and make the
 * guarantee explicit. This is a total order, so it stays consistent for the
 * metadata storage and the alias resolver, which use the same comparator.
 */
final readonly class ContributionOrderComparator implements Comparator
{
    /** @var array<string, int> */
    private array $ranks;

    public function __construct(MigrationRegistry $migrations)
    {
        $this->ranks = array_flip(array_keys($migrations->all()));
    }

    public function compare(Version $a, Version $b): int
    {
        return $this->rank((string) $a) <=> $this->rank((string) $b)
            ?: strcmp((string) $a, (string) $b);
    }

    /**
     * An unregistered namespace sorts last: the unknown never wins a race
     * against a namespace whose position was declared.
     */
    private function rank(string $version): int
    {
        $class = ltrim($version, '\\');

        foreach ($this->ranks as $namespace => $rank) {
            if (str_starts_with($class, $namespace . '\\')) {
                return $rank;
            }
        }

        return PHP_INT_MAX;
    }
}
