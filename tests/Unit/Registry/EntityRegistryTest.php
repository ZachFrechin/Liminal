<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Registry;

use Liminal\Registry\EntityRegistry;
use Liminal\Registry\Exception\FrozenRegistryException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityRegistry::class)]
final class EntityRegistryTest extends TestCase
{
    /**
     * Doctrine's MappingDriverChain returns the first driver whose namespace is a
     * prefix of the class name. Registering the shorter namespace first would make
     * it swallow the longer one's entities, so the registry must reorder.
     */
    public function testLongerNamespacesComeFirstRegardlessOfInsertionOrder(): void
    {
        $registry = new EntityRegistry();
        $registry->add('Liminal\Module\Stock', '/stock/Entity');
        $registry->add('Liminal\Module\StockAdvanced', '/stock-advanced/Entity');

        self::assertSame(
            ['Liminal\Module\StockAdvanced', 'Liminal\Module\Stock'],
            array_keys($registry->all()),
        );
    }

    public function testOrderingIsStableForEqualLengthNamespaces(): void
    {
        $registry = new EntityRegistry();
        $registry->add('Liminal\Module\Bbb', '/bbb');
        $registry->add('Liminal\Module\Aaa', '/aaa');

        self::assertSame(
            ['Liminal\Module\Aaa', 'Liminal\Module\Bbb'],
            array_keys($registry->all()),
        );
    }

    public function testLeadingBackslashIsNormalised(): void
    {
        $registry = new EntityRegistry();
        $registry->add('\Liminal\Module\Stock', '/stock');

        self::assertSame(['Liminal\Module\Stock'], array_keys($registry->all()));
    }

    public function testSeveralPathsAccumulateUnderOneNamespace(): void
    {
        $registry = new EntityRegistry();
        $registry->add('Liminal\Module\Stock', '/a');
        $registry->add('Liminal\Module\Stock', '/b');

        self::assertSame(['/a', '/b'], $registry->all()['Liminal\Module\Stock']);
    }

    public function testContributingAfterFreezeIsRejected(): void
    {
        $registry = new EntityRegistry();
        $registry->freeze();

        $this->expectException(FrozenRegistryException::class);

        $registry->add('Liminal\Module\Stock', '/stock');
    }

    public function testTheOrderingSurvivesFreezing(): void
    {
        $registry = new EntityRegistry();
        $registry->add('Liminal\Module\Stock', '/stock');
        $registry->add('Liminal\Module\StockAdvanced', '/stock-advanced');
        $registry->freeze();

        self::assertSame(
            ['Liminal\Module\StockAdvanced', 'Liminal\Module\Stock'],
            array_keys($registry->all()),
        );
    }

    public function testFreezingTwiceIsHarmless(): void
    {
        $registry = new EntityRegistry();
        $registry->add('Liminal\Module\Stock', '/stock');
        $registry->freeze();
        $registry->freeze();

        self::assertTrue($registry->isFrozen());
    }
}
