<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Console;

use Liminal\Kernel;
use Liminal\Registry\CommandRegistry;
use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * The regression test the convention never had. The console resolves EVERY
 * registered command eagerly, so one command constructor-injecting Connection,
 * EntityManagerInterface or SettingsService breaks every command — `doctor`
 * included, on the very checkout where doctor is most needed. This boots the
 * real application root without a DSN and resolves the full command list; a
 * violation fails here with the offending class in the trace, not in
 * production with a DSN error.
 */
#[CoversNothing]
final class CommandResolutionTest extends TestCase
{
    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN');
    }

    protected function tearDown(): void
    {
        if ($this->previousDsn !== null) {
            putenv('LIMINAL_DSN=' . $this->previousDsn);
        }
    }

    public function testEveryDeclaredCommandResolvesOnADsnLessCheckout(): void
    {
        $kernel = new Kernel(dirname(__DIR__, 3));
        $container = $kernel->container();

        $classes = $kernel->registries()->get(CommandRegistry::class)->all();

        self::assertNotSame([], $classes);

        foreach ($classes as $class) {
            self::assertInstanceOf(Command::class, $container->get($class), $class);
        }
    }
}
