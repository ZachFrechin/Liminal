<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Registry;

use Liminal\Lib\System\Console\DoctorCommand;
use Liminal\Registry\CommandRegistry;
use Liminal\Registry\EntityRegistry;
use Liminal\Registry\Exception\DuplicateContributionException;
use Liminal\Registry\MenuItem;
use Liminal\Registry\MenuRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\Permission;
use Liminal\Registry\PermissionRegistry;
use Liminal\Registry\RouteRegistry;
use Liminal\Registry\SettingDefinition;
use Liminal\Registry\SettingScope;
use Liminal\Registry\SettingsRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One policy, every registry: colliding contributions fail at boot, loudly,
 * instead of last-contributor-wins. Deliberate overriding becomes an explicit
 * API when modules arrive in phase 2.
 */
#[CoversClass(DuplicateContributionException::class)]
#[CoversClass(RouteRegistry::class)]
#[CoversClass(CommandRegistry::class)]
#[CoversClass(PermissionRegistry::class)]
#[CoversClass(SettingsRegistry::class)]
#[CoversClass(MigrationRegistry::class)]
#[CoversClass(EntityRegistry::class)]
#[CoversClass(MenuRegistry::class)]
final class DuplicateContributionTest extends TestCase
{
    public function testTheSameMethodAndPathIsRefused(): void
    {
        $registry = new RouteRegistry();
        $registry->get('/thing', 'Handler');

        $this->expectException(DuplicateContributionException::class);

        $registry->get('/thing', 'OtherHandler');
    }

    public function testTheSamePathUnderAnotherMethodIsAccepted(): void
    {
        $registry = new RouteRegistry();
        $registry->get('/thing', 'Handler');
        $registry->post('/thing', 'Handler');

        self::assertCount(2, $registry->all());
    }

    public function testARouteNameIsUnique(): void
    {
        $registry = new RouteRegistry();
        $registry->get('/a', 'Handler', 'thing.list');

        $this->expectException(DuplicateContributionException::class);

        $registry->post('/b', 'Handler', 'thing.list');
    }

    public function testAPermissionCodeIsUnique(): void
    {
        $registry = new PermissionRegistry();
        $registry->add(new Permission('thing.read', 'Read things'));

        $this->expectException(DuplicateContributionException::class);

        $registry->add(new Permission('thing.read', 'Read things again'));
    }

    public function testASettingKeyIsUnique(): void
    {
        $registry = new SettingsRegistry();
        $registry->add(new SettingDefinition('thing.limit', SettingScope::Global, 10));

        $this->expectException(DuplicateContributionException::class);

        $registry->add(new SettingDefinition('thing.limit', SettingScope::Company, 20));
    }

    public function testAMigrationNamespaceIsUnique(): void
    {
        $registry = new MigrationRegistry();
        $registry->add('Liminal\Module\Stock\Migrations', '/stock/Migrations');

        $this->expectException(DuplicateContributionException::class);

        $registry->add('Liminal\Module\Stock\Migrations', '/elsewhere');
    }

    public function testACommandClassIsUnique(): void
    {
        $registry = new CommandRegistry();
        $registry->add(DoctorCommand::class);

        $this->expectException(DuplicateContributionException::class);

        $registry->add(DoctorCommand::class);
    }

    public function testAnEntityPathIsUniquePerNamespace(): void
    {
        $registry = new EntityRegistry();
        $registry->add('Liminal\Module\Stock', '/stock/Entity');

        $this->expectException(DuplicateContributionException::class);

        $registry->add('Liminal\Module\Stock', '/stock/Entity');
    }

    /**
     * Menu items have no natural key, so the menu registry deliberately stays
     * out of the duplicate policy: collisions there are a rendering concern.
     */
    public function testMenuItemsAreNotDeduplicated(): void
    {
        $registry = new MenuRegistry();
        $registry->add(new MenuItem('Things', 'thing.list'));
        $registry->add(new MenuItem('Things', 'thing.list'));

        self::assertCount(2, $registry->all());
    }
}
