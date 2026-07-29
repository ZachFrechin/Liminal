<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Kernel;
use Liminal\Lib\Database\Console\MigrateCommand;
use Liminal\Lib\Database\Console\MigrateStatusCommand;
use Liminal\Lib\Database\DatabaseContributor;
use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The migrate surface through the front door: full kernel boot, commands
 * resolved from the container, real core migration executed on MariaDB.
 */
#[CoversNothing]
final class MigrationCommandsTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/../..';

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping migration command test.');
        }

        $connection = $this->connection();

        foreach (['core_session', 'core_setting', 'core_module_company', 'core_module', 'core_company', 'core_migration_version'] as $table) {
            $connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }

        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    public function testMigrateExecutesTheCoreMigrationThenReportsUpToDate(): void
    {
        $tester = $this->tester(MigrateCommand::class);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Version20260729000000', $tester->getDisplay());

        $again = $this->tester(MigrateCommand::class);

        self::assertSame(Command::SUCCESS, $again->execute([]));
        self::assertStringContainsString('Nothing to migrate', $again->getDisplay());
    }

    public function testAnUnknownModuleNamespaceIsInvalid(): void
    {
        $tester = $this->tester(MigrateCommand::class);

        self::assertSame(Command::INVALID, $tester->execute(['--module' => 'Nope\Namespace']));
        self::assertStringContainsString('Unknown migration namespace', $tester->getDisplay());
        self::assertStringContainsString(DatabaseContributor::MIGRATION_NAMESPACE, $tester->getDisplay());
    }

    public function testStatusListsTheCoreNamespace(): void
    {
        $tester = $this->tester(MigrateStatusCommand::class);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString(DatabaseContributor::MIGRATION_NAMESPACE, $tester->getDisplay());
    }

    public function testWithoutADsnMigrateFailsAndStatusSkips(): void
    {
        putenv('LIMINAL_DSN');

        self::assertSame(Command::FAILURE, $this->tester(MigrateCommand::class)->execute([]));

        $status = $this->tester(MigrateStatusCommand::class);

        self::assertSame(Command::SUCCESS, $status->execute([]));
        self::assertStringContainsString('SKIP', $status->getDisplay());
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
