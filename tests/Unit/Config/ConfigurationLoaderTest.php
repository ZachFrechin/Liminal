<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Config;

use Liminal\Config\ConfigurationLoader;
use Liminal\Config\Exception\MissingConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurationLoader::class)]
final class ConfigurationLoaderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures';

    public function testEachLoadedFileBecomesATopLevelKey(): void
    {
        $config = new ConfigurationLoader(self::FIXTURES)->load('good');

        self::assertSame('value', $config->string('good.key'));
    }

    /**
     * Requested files are required files: a typo must fail the boot with the
     * path in the message, not surface later as a puzzling missing key.
     */
    public function testAMissingFileFailsTheLoad(): void
    {
        $this->expectException(MissingConfigurationException::class);

        new ConfigurationLoader(self::FIXTURES)->load('does-not-exist');
    }

    public function testAFileNotReturningAnArrayFailsTheLoad(): void
    {
        $this->expectException(MissingConfigurationException::class);

        new ConfigurationLoader(self::FIXTURES)->load('not-an-array');
    }
}
