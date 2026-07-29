<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Config;

use Liminal\Config\Configuration;
use Liminal\Config\Exception\MissingConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    public function testTypedAccessorsTraverseDotNotation(): void
    {
        $config = new Configuration(['app' => ['name' => 'Liminal', 'debug' => true, 'workers' => 4]]);

        self::assertSame('Liminal', $config->string('app.name'));
        self::assertTrue($config->bool('app.debug'));
        self::assertSame(4, $config->int('app.workers'));
        self::assertTrue($config->has('app.name'));
        self::assertFalse($config->has('app.missing'));
    }

    public function testNumbersAreAcceptedWhereStringsAreAsked(): void
    {
        $config = new Configuration(['db' => ['port' => 3306]]);

        self::assertSame('3306', $config->string('db.port'));
    }

    public function testDefaultsCoverMissingKeys(): void
    {
        $config = new Configuration([]);

        self::assertSame('fallback', $config->string('nope', 'fallback'));
        self::assertFalse($config->bool('nope', false));
        self::assertSame(7, $config->int('nope', 7));
    }

    public function testAMissingKeyWithoutDefaultThrows(): void
    {
        $this->expectException(MissingConfigurationException::class);

        new Configuration([])->string('app.name');
    }

    public function testAMistypedValueWithoutDefaultThrows(): void
    {
        $this->expectException(MissingConfigurationException::class);

        new Configuration(['app' => ['debug' => 'yes']])->bool('app.debug');
    }

    public function testAnAbsentListIsEmpty(): void
    {
        self::assertSame([], new Configuration([])->stringList('app.libs'));
    }

    public function testAListOfStringsComesBackAsAList(): void
    {
        $config = new Configuration(['app' => ['libs' => ['A', 'B']]]);

        self::assertSame(['A', 'B'], $config->stringList('app.libs'));
    }

    /**
     * Silently filtering a bad entry would make a mistyped lib class in
     * app.libs vanish from the boot without a trace.
     */
    public function testANonStringListEntryThrowsInsteadOfVanishing(): void
    {
        $config = new Configuration(['app' => ['libs' => ['A', 42]]]);

        $this->expectException(MissingConfigurationException::class);

        $config->stringList('app.libs');
    }

    public function testAScalarWhereAListIsExpectedThrows(): void
    {
        $config = new Configuration(['app' => ['libs' => 'NotAList']]);

        $this->expectException(MissingConfigurationException::class);

        $config->stringList('app.libs');
    }
}
