<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Database;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\AvailableMigrationsList;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\MigrationPlanCalculator;
use Doctrine\Migrations\Version\Version;
use Liminal\Lib\Database\Migration\ScopedPlanCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScopedPlanCalculator::class)]
final class ScopedPlanCalculatorTest extends TestCase
{
    private const ALPHA = 'App\Module\Alpha\Migrations';
    private const BETA = 'App\Module\Beta\Migrations';

    public function testOnlyItsOwnNamespaceSurvivesTheMigrationList(): void
    {
        $calculator = new ScopedPlanCalculator($this->inner(), self::ALPHA);

        $versions = array_map(
            static fn (AvailableMigration $m): string => (string) $m->getVersion(),
            $calculator->getMigrations()->getItems(),
        );

        self::assertSame([self::ALPHA . '\Version1'], $versions);
    }

    public function testOnlyItsOwnNamespaceSurvivesAPlan(): void
    {
        $calculator = new ScopedPlanCalculator($this->inner(), self::ALPHA);

        $plan = $calculator->getPlanUntilVersion(new Version(self::ALPHA . '\Version1'));

        self::assertCount(1, $plan);
        self::assertSame(self::ALPHA . '\Version1', (string) $plan->getFirst()->getVersion());
    }

    /**
     * A prefix test alone would let "App\Module\AlphaExtra" pass as "App\Module\Alpha",
     * so the separator has to be part of the comparison.
     */
    public function testANamespaceThatMerelySharesAPrefixIsNotClaimed(): void
    {
        $inner = $this->calculatorFor([
            $this->migration(self::ALPHA . 'Extra\Version9'),
        ]);

        self::assertCount(0, (new ScopedPlanCalculator($inner, self::ALPHA))->getMigrations()->getItems());
    }

    private function inner(): MigrationPlanCalculator
    {
        return $this->calculatorFor([
            $this->migration(self::ALPHA . '\Version1'),
            $this->migration(self::BETA . '\Version2'),
        ]);
    }

    private function migration(string $version): AvailableMigration
    {
        return new AvailableMigration(
            new Version($version),
            $this->createMock(AbstractMigration::class),
        );
    }

    /**
     * @param list<AvailableMigration> $migrations
     */
    private function calculatorFor(array $migrations): MigrationPlanCalculator
    {
        $calculator = $this->createMock(MigrationPlanCalculator::class);
        $calculator->method('getMigrations')->willReturn(new AvailableMigrationsList($migrations));

        $plans = array_map(
            static fn (AvailableMigration $m): MigrationPlan
                => new MigrationPlan($m->getVersion(), $m->getMigration(), Direction::UP),
            $migrations,
        );

        $calculator->method('getPlanUntilVersion')->willReturn(new MigrationPlanList($plans, Direction::UP));
        $calculator->method('getPlanForVersions')->willReturn(new MigrationPlanList($plans, Direction::UP));

        return $calculator;
    }
}
