<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Registry;

use Liminal\Registry\Exception\UndeclaredSettingException;
use Liminal\Registry\SettingDefinition;
use Liminal\Registry\SettingScope;
use Liminal\Registry\SettingsRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingsRegistry::class)]
final class SettingsRegistryTest extends TestCase
{
    public function testADeclaredSettingIsReadableWithItsDefinition(): void
    {
        $definition = new SettingDefinition('system.maintenance', SettingScope::Global, false, 'Maintenance mode');

        $registry = new SettingsRegistry();
        $registry->add($definition);

        self::assertTrue($registry->has('system.maintenance'));
        self::assertSame($definition, $registry->definition('system.maintenance'));
        self::assertSame(['system.maintenance' => $definition], $registry->all());
    }

    /**
     * Reads are only legal against declared keys: this boundary is what stops
     * a module from consuming configuration it never announced.
     */
    public function testReadingAnUndeclaredSettingIsRefused(): void
    {
        $registry = new SettingsRegistry();

        self::assertFalse($registry->has('never.declared'));

        $this->expectException(UndeclaredSettingException::class);

        $registry->definition('never.declared');
    }
}
