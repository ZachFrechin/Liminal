<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Registry;

use Liminal\Registry\Exception\DuplicateContributionException;
use Liminal\Registry\Exception\FrozenRegistryException;
use Liminal\Registry\Exception\UnknownRegistryException;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;
use Liminal\Registry\SettingsRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistryCollection::class)]
final class RegistryCollectionTest extends TestCase
{
    public function testGetReturnsTheRegistryOfTheRequestedType(): void
    {
        $routes = new RouteRegistry();
        $collection = new RegistryCollection([$routes]);

        self::assertSame($routes, $collection->get(RouteRegistry::class));
    }

    public function testGetRejectsAnUnregisteredType(): void
    {
        $this->expectException(UnknownRegistryException::class);

        (new RegistryCollection())->get(RouteRegistry::class);
    }

    public function testFreezeCascadesToEveryRegistry(): void
    {
        $collection = new RegistryCollection([new RouteRegistry(), new SettingsRegistry()]);
        $collection->freeze();

        self::assertTrue($collection->isFrozen());
        self::assertTrue($collection->get(RouteRegistry::class)->isFrozen());
        self::assertTrue($collection->get(SettingsRegistry::class)->isFrozen());

        $this->expectException(FrozenRegistryException::class);
        $collection->get(RouteRegistry::class)->get('/late', 'Handler');
    }

    /**
     * The collection itself is part of the frozen surface: a new registry after
     * boot would be a fresh, unfrozen extension point — exactly what freeze()
     * promises cannot exist.
     */
    public function testRegisteringAfterFreezeIsRejected(): void
    {
        $collection = new RegistryCollection();
        $collection->freeze();

        $this->expectException(FrozenRegistryException::class);

        $collection->register(new RouteRegistry());
    }

    public function testRegisteringTheSameRegistryClassTwiceIsRejected(): void
    {
        $collection = new RegistryCollection([new RouteRegistry()]);

        $this->expectException(DuplicateContributionException::class);

        $collection->register(new RouteRegistry());
    }
}
