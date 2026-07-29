<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Kernel;
use Liminal\Lib\System\Console\InstallCommand;
use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A virgin database becomes a runnable instance in one command — and running
 * it again changes nothing.
 */
#[CoversNothing]
final class InstallCommandTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/../..';

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping install test.');
        }

        $connection = $this->connection();

        foreach (['core_setting', 'core_module_company', 'core_module', 'core_company', 'core_migration_version'] as $table) {
            $connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }

        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    public function testAFreshInstallMigratesAndSeedsThenStaysIdempotent(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Version20260729000000', $tester->getDisplay());
        self::assertStringContainsString('seeded "MAIN" (id 1)', $tester->getDisplay());

        $connection = $this->connection();

        self::assertEquals(1, $connection->fetchOne('SELECT COUNT(*) FROM core_company'));
        self::assertEquals('MAIN', $connection->fetchOne('SELECT code FROM core_company WHERE id = 1'));

        $again = $this->tester();

        self::assertSame(Command::SUCCESS, $again->execute([]));
        self::assertStringContainsString('Already installed', $again->getDisplay());
        self::assertEquals(1, $connection->fetchOne('SELECT COUNT(*) FROM core_company'));
    }

    public function testACustomCompanyCodeAndNameAreHonoured(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--company-code' => 'ACME',
            '--company-name' => 'Acme Corp',
        ]));

        self::assertEquals(
            'Acme Corp',
            $this->connection()->fetchOne('SELECT name FROM core_company WHERE code = ?', ['ACME']),
        );
    }

    public function testWithoutADsnInstallFailsWithTheRemedy(): void
    {
        putenv('LIMINAL_DSN');

        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('set LIMINAL_DSN', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $command = new Kernel(self::ROOT)->container()->get(InstallCommand::class);

        self::assertInstanceOf(InstallCommand::class, $command);

        return new CommandTester($command);
    }
}
