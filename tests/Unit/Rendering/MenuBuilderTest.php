<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Rendering;

use Doctrine\DBAL\Connection;
use Liminal\Lib\Database\Migration\MigrationFactory;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Module\ModuleManager;
use Liminal\Lib\Rendering\Exception\RenderingException;
use Liminal\Lib\Rendering\Menu\MenuBuilder;
use Liminal\Lib\Rendering\Menu\MenuNode;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Lib\Security\Authorization\Gate;
use Liminal\Lib\Security\Contract\AuthenticatedUser;
use Liminal\Lib\Security\Contract\PermissionResolver;
use Liminal\Lib\Security\Exception\UndeclaredPermissionException;
use Liminal\Registry\Contract\Module;
use Liminal\Registry\MenuItem;
use Liminal\Registry\MenuRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\ModuleRegistry;
use Liminal\Registry\Permission;
use Liminal\Registry\PermissionRegistry;
use Liminal\Registry\RegistryCollection;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MenuBuilder::class)]
#[CoversClass(MenuNode::class)]
final class MenuBuilderTest extends TestCase
{
    private const COMPANY = 1;

    /**
     * The property that keeps public pages renderable with no database in
     * reach: an anonymous request must not even look up enablement.
     */
    public function testAnAnonymousUserGetsAnEmptyMenuWithoutTouchingTheDatabase(): void
    {
        $builder = $this->builder(
            items: [new MenuItem('Stock', 'stock.index')],
            enabled: ['stock' => true],
            anonymous: true,
            failOnQuery: true,
        );

        self::assertSame([], $builder->build());
    }

    public function testADisabledModulesItemsDisappear(): void
    {
        $builder = $this->builder(
            items: [new MenuItem('Stock', 'stock.index'), new MenuItem('Invoices', 'invoicing.index')],
            enabled: ['stock' => true, 'invoicing' => false],
        );

        self::assertSame(['Stock'], $this->labels($builder->build()));
    }

    /**
     * A lib route's prefix names no declared module, so enablement never
     * applies to it — the module gate's own rule.
     */
    public function testLibItemsAreNeverGatedByEnablement(): void
    {
        $builder = $this->builder(
            items: [new MenuItem('Health', 'system.health')],
            enabled: ['stock' => false],
        );

        self::assertSame(['Health'], $this->labels($builder->build()));
    }

    public function testAPermissionGatedItemDisappearsWhenTheGateDenies(): void
    {
        $builder = $this->builder(
            items: [
                new MenuItem('Open', 'stock.index'),
                new MenuItem('Restricted', 'stock.admin', 'stock.manage'),
            ],
            enabled: ['stock' => true],
            allowed: false,
        );

        self::assertSame(['Open'], $this->labels($builder->build()));
    }

    public function testATypoedPermissionFailsLoud(): void
    {
        $builder = $this->builder(
            items: [new MenuItem('Typo', 'stock.index', 'stock.typo')],
            enabled: ['stock' => true],
        );

        $this->expectException(UndeclaredPermissionException::class);

        $builder->build();
    }

    public function testChildrenNestUnderTheirParentInPriorityOrder(): void
    {
        $builder = $this->builder(
            items: [
                new MenuItem('Second child', 'stock.second', priority: 200, parent: 'stock.index'),
                new MenuItem('Stock', 'stock.index'),
                new MenuItem('First child', 'stock.first', priority: 10, parent: 'stock.index'),
            ],
            enabled: ['stock' => true],
        );

        $tree = $builder->build();

        self::assertSame(['Stock'], $this->labels($tree));
        self::assertSame(['First child', 'Second child'], $this->labels($tree[0]->children));
    }

    /**
     * Hiding only the parent would leak a disabled module's shape through its
     * children, so the whole subtree goes.
     */
    public function testAChildOfAHiddenParentStaysHidden(): void
    {
        $builder = $this->builder(
            items: [
                new MenuItem('Restricted', 'stock.admin', 'stock.manage'),
                new MenuItem('Child', 'stock.child', parent: 'stock.admin'),
            ],
            enabled: ['stock' => true],
            allowed: false,
        );

        self::assertSame([], $builder->build());
    }

    public function testAParentNamingNoContributedRouteFailsLoud(): void
    {
        $builder = $this->builder(
            items: [new MenuItem('Orphan', 'stock.child', parent: 'stock.nowhere')],
            enabled: ['stock' => true],
        );

        $this->expectException(RenderingException::class);
        $this->expectExceptionMessageMatches('/no contributed item declares/');

        $builder->build();
    }

    /**
     * @param list<MenuNode> $nodes
     *
     * @return list<string>
     */
    private function labels(array $nodes): array
    {
        return array_map(static fn(MenuNode $node): string => $node->item->label, $nodes);
    }

    /**
     * @param list<MenuItem>      $items
     * @param array<string, bool> $enabled module name => enabled for the company
     */
    private function builder(
        array $items,
        array $enabled,
        bool $allowed = true,
        bool $failOnQuery = false,
        bool $anonymous = false,
    ): MenuBuilder {
        $menu = new MenuRegistry();

        foreach ($items as $item) {
            $menu->add($item);
        }

        $menu->freeze();

        $modules = new ModuleRegistry(array_map($this->module(...), array_keys($enabled)));

        $currentUser = new CurrentUser();

        if (!$anonymous) {
            $currentUser->set($this->user());
        }

        $permissions = new PermissionRegistry();
        $permissions->add(new Permission('stock.manage', 'Manage stock'));

        return new MenuBuilder(
            $menu,
            $modules,
            $this->manager($modules, $enabled, $failOnQuery),
            new Gate($currentUser, new CompanyContext(self::COMPANY), $this->resolver($allowed), $permissions),
            $currentUser,
            new CompanyContext(self::COMPANY),
        );
    }

    /**
     * @param array<string, bool> $enabled
     */
    private function manager(ModuleRegistry $modules, array $enabled, bool $failOnQuery): ModuleManager
    {
        $connection = $this->createMock(Connection::class);

        if ($failOnQuery) {
            $connection->method('fetchAllAssociative')
                ->willThrowException(new LogicException('The menu must not query for an anonymous request.'));
        } else {
            $rows = [];

            foreach ($enabled as $name => $isEnabled) {
                if ($isEnabled) {
                    $rows[] = ['name' => $name];
                }
            }

            $connection->method('fetchAllAssociative')->willReturn($rows);
        }

        $migrations = new MigrationRegistry();

        return new ModuleManager(
            $modules,
            new MigrationRunner(new MigrationFactory($migrations), $migrations, static fn(): Connection => $connection),
            static fn(): Connection => $connection,
        );
    }

    private function module(string $name): Module
    {
        return new class ($name) implements Module {
            public function __construct(private readonly string $name) {}

            public function name(): string
            {
                return $this->name;
            }

            public function version(): string
            {
                return '1.0.0';
            }

            public function migrationNamespace(): ?string
            {
                return null;
            }

            public function contribute(RegistryCollection $registries): void {}
        };
    }

    private function resolver(bool $allowed): PermissionResolver
    {
        return new class ($allowed) implements PermissionResolver {
            public function __construct(private readonly bool $allowed) {}

            public function allows(AuthenticatedUser $user, string $permission, int $companyId): bool
            {
                return $this->allowed;
            }
        };
    }

    private function user(): AuthenticatedUser
    {
        return new class implements AuthenticatedUser {
            public function id(): int
            {
                return 7;
            }

            public function displayName(): string
            {
                return 'Fixture user';
            }

            public function accessibleCompanyIds(): array
            {
                return [1];
            }
        };
    }
}
