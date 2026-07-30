<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Database;

use Doctrine\Migrations\Version\Version;
use Liminal\Lib\Database\Migration\ContributionOrderComparator;
use Liminal\Registry\MigrationRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContributionOrderComparator::class)]
final class ContributionOrderComparatorTest extends TestCase
{
    /**
     * The whole point: alphabetical order on class names would run a
     * third-party module's migration before the core tables it depends on
     * exist, because "Acme\…" sorts before "Liminal\…".
     */
    public function testALibsMigrationsSortBeforeAModulesRegardlessOfClassName(): void
    {
        $comparator = $this->comparator([
            'Liminal\Lib\Database\Migrations' => '/database',
            'Acme\Erp\Crm\Migrations' => '/crm',
        ]);

        $lib = new Version('Liminal\Lib\Database\Migrations\Version20260729000000');
        $module = new Version('Acme\Erp\Crm\Migrations\Version20260101000000');

        self::assertLessThan(0, $comparator->compare($lib, $module));
        self::assertGreaterThan(0, $comparator->compare($module, $lib));
    }

    public function testVersionsInsideOneNamespaceKeepTheirTimestampOrder(): void
    {
        $comparator = $this->comparator(['Liminal\Lib\Security\Migrations' => '/security']);

        $first = new Version('Liminal\Lib\Security\Migrations\Version20260729000001');
        $second = new Version('Liminal\Lib\Security\Migrations\Version20260730000000');

        self::assertLessThan(0, $comparator->compare($first, $second));
    }

    /**
     * An unregistered namespace must never win a race against one whose
     * position was declared.
     */
    public function testAnUnregisteredNamespaceSortsLast(): void
    {
        $comparator = $this->comparator(['Liminal\Lib\Database\Migrations' => '/database']);

        $known = new Version('Liminal\Lib\Database\Migrations\Version20260729000000');
        $stranger = new Version('Aaa\Unknown\Migrations\Version19990101000000');

        self::assertLessThan(0, $comparator->compare($known, $stranger));
    }

    /**
     * @param array<non-empty-string, string> $namespaces
     */
    private function comparator(array $namespaces): ContributionOrderComparator
    {
        $registry = new MigrationRegistry();

        foreach ($namespaces as $namespace => $directory) {
            $registry->add($namespace, $directory);
        }

        return new ContributionOrderComparator($registry);
    }
}
