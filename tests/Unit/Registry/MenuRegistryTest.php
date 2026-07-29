<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Registry;

use Liminal\Registry\MenuItem;
use Liminal\Registry\MenuRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MenuRegistry::class)]
final class MenuRegistryTest extends TestCase
{
    public function testItemsComeBackOrderedByAscendingPriority(): void
    {
        $registry = new MenuRegistry();
        $registry->add(new MenuItem('Settings', 'settings.index', priority: 900));
        $registry->add(new MenuItem('Home', 'system.health', priority: 10));
        $registry->add(new MenuItem('Things', 'thing.list'));

        self::assertSame(
            ['Home', 'Things', 'Settings'],
            array_map(static fn(MenuItem $item): string => $item->label, $registry->all()),
        );
    }

    public function testTheOrderingSurvivesFreezing(): void
    {
        $registry = new MenuRegistry();
        $registry->add(new MenuItem('Late', 'late.route', priority: 500));
        $registry->add(new MenuItem('Early', 'early.route', priority: 1));
        $registry->freeze();

        self::assertSame(
            ['Early', 'Late'],
            array_map(static fn(MenuItem $item): string => $item->label, $registry->all()),
        );
    }
}
