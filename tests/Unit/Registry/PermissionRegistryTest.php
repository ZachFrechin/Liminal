<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Registry;

use Liminal\Registry\Permission;
use Liminal\Registry\PermissionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PermissionRegistry::class)]
final class PermissionRegistryTest extends TestCase
{
    public function testPermissionsAreKeyedByTheirCode(): void
    {
        $read = new Permission('thing.read', 'Read things');
        $write = new Permission('thing.write', 'Write things', 'things');

        $registry = new PermissionRegistry();
        $registry->add($read);
        $registry->add($write);

        self::assertTrue($registry->has('thing.read'));
        self::assertFalse($registry->has('thing.delete'));
        self::assertSame(['thing.read' => $read, 'thing.write' => $write], $registry->all());
    }
}
