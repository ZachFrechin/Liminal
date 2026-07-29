<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Kernel;
use Liminal\Lib\Module\Console\DisableCommand;
use Liminal\Lib\Module\Console\EnableCommand;
use Liminal\Lib\Module\Console\InstallCommand as ModuleInstallCommand;
use Liminal\Lib\Module\Console\ListCommand;
use Liminal\Lib\System\Console\InstallCommand as SystemInstallCommand;
use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The module lifecycle through the front door: a fixture root declaring one
 * module, a full kernel boot, commands resolved from the container.
 */
#[CoversNothing]
final class ModuleCommandsTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/Fixtures/module-root';

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping module command test.');
        }

        $connection = $this->connection();

        $tables = ['test_module_fixture', 'core_session', 'core_setting', 'core_module_company', 'core_module', 'core_company', 'core_migration_version'];

        foreach ($tables as $table) {
            $connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }

        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    public function testModuleInstallMigratesRecordsAndIsIdempotent(): void
    {
        // The global install migrates every registered namespace — including
        // the declared module's, whose tables therefore pre-exist its record.
        self::assertSame(Command::SUCCESS, $this->tester(SystemInstallCommand::class)->execute([]));

        $install = $this->tester(ModuleInstallCommand::class);

        self::assertSame(Command::SUCCESS, $install->execute(['name' => 'fixture']));
        self::assertStringContainsString('nothing to migrate', $install->getDisplay());
        self::assertStringContainsString('installed (version 1.0.0)', $install->getDisplay());

        $again = $this->tester(ModuleInstallCommand::class);

        self::assertSame(Command::SUCCESS, $again->execute(['name' => 'fixture']));
        self::assertStringContainsString('already installed (version 1.0.0)', $again->getDisplay());
    }

    public function testModuleEnableThenDisableTogglesAndListShowsIt(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester(SystemInstallCommand::class)->execute([]));
        self::assertSame(Command::SUCCESS, $this->tester(ModuleInstallCommand::class)->execute(['name' => 'fixture']));

        $enable = $this->tester(EnableCommand::class);

        self::assertSame(Command::SUCCESS, $enable->execute(['name' => 'fixture', 'company-id' => '1']));
        self::assertStringContainsString('enabled for company 1', $enable->getDisplay());

        $list = $this->tester(ListCommand::class);

        self::assertSame(Command::SUCCESS, $list->execute([]));
        self::assertStringContainsString('fixture', $list->getDisplay());
        self::assertStringContainsString('1.0.0', $list->getDisplay());

        $disable = $this->tester(DisableCommand::class);

        self::assertSame(Command::SUCCESS, $disable->execute(['name' => 'fixture', 'company-id' => '1']));
        self::assertStringContainsString('disabled for company 1', $disable->getDisplay());
    }

    public function testAnUnknownModuleNameIsInvalidAndListsDeclaredModules(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester(SystemInstallCommand::class)->execute([]));

        $install = $this->tester(ModuleInstallCommand::class);

        self::assertSame(Command::INVALID, $install->execute(['name' => 'ghost']));
        self::assertStringContainsString('Unknown module "ghost"', $install->getDisplay());
        self::assertStringContainsString('fixture', $install->getDisplay());
    }

    public function testWithoutADsnMutatingCommandsFailAndListSkips(): void
    {
        putenv('LIMINAL_DSN');

        self::assertSame(Command::FAILURE, $this->tester(ModuleInstallCommand::class)->execute(['name' => 'fixture']));
        self::assertSame(
            Command::FAILURE,
            $this->tester(EnableCommand::class)->execute(['name' => 'fixture', 'company-id' => '1']),
        );

        $list = $this->tester(ListCommand::class);

        self::assertSame(Command::SUCCESS, $list->execute([]));
        self::assertStringContainsString('SKIP', $list->getDisplay());
    }

    /**
     * @param class-string<Command> $class
     */
    private function tester(string $class): CommandTester
    {
        $command = new Kernel(self::ROOT)->container()->get($class);

        self::assertInstanceOf(Command::class, $command);

        return new CommandTester($command);
    }
}
