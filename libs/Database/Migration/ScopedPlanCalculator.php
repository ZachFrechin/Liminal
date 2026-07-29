<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Migration;

use Doctrine\Migrations\Metadata\AvailableMigrationsList;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\Version\MigrationPlanCalculator;
use Doctrine\Migrations\Version\Version;

/**
 * Restricts every migration plan to a single namespace.
 *
 * doctrine:migrations:migrate operates on every registered namespace at once, so
 * installing one module would run every other module's pending migrations too.
 * MigrationPlanCalculator is an interface and DependencyFactory::setDefinition()
 * accepts a replacement, so scoping is a decorator rather than a bespoke migrator:
 * the real calculator still does the ordering and dependency work, and this only
 * drops what belongs to other namespaces.
 */
final readonly class ScopedPlanCalculator implements MigrationPlanCalculator
{
    public function __construct(
        private MigrationPlanCalculator $inner,
        private string $namespace,
    ) {
    }

    /** @param Version[] $versions */
    public function getPlanForVersions(array $versions, string $direction): MigrationPlanList
    {
        return $this->restrict($this->inner->getPlanForVersions($versions, $direction));
    }

    public function getPlanUntilVersion(Version $to): MigrationPlanList
    {
        return $this->restrict($this->inner->getPlanUntilVersion($to));
    }

    public function getMigrations(): AvailableMigrationsList
    {
        $migrations = array_filter(
            $this->inner->getMigrations()->getItems(),
            fn ($migration): bool => $this->owns((string) $migration->getVersion()),
        );

        return new AvailableMigrationsList(array_values($migrations));
    }

    private function restrict(MigrationPlanList $plans): MigrationPlanList
    {
        $items = array_filter(
            $plans->getItems(),
            fn ($plan): bool => $this->owns((string) $plan->getVersion()),
        );

        return new MigrationPlanList(array_values($items), $plans->getDirection());
    }

    /**
     * A Version stringifies to the migration's fully-qualified class name, so a
     * namespace prefix test is enough to decide ownership.
     */
    private function owns(string $version): bool
    {
        return str_starts_with(ltrim($version, '\\'), rtrim($this->namespace, '\\') . '\\');
    }
}
