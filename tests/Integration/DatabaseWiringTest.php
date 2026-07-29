<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Liminal\Kernel;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\System\Console\DoctorCommand;
use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Proves phase 1 is consumable through the front door: a full kernel boot,
 * then the database services resolved from the container — no hand-built
 * factories anywhere.
 */
#[CoversNothing]
final class DatabaseWiringTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/../..';

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping database wiring test.');
        }

        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    public function testTheContainerServesAWorkingEntityManager(): void
    {
        $entityManager = new Kernel(self::ROOT)->container()->get(EntityManagerInterface::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertEquals(1, $entityManager->getConnection()->fetchOne('SELECT 1'));
    }

    public function testTheCompanyContextIsASharedBootstrapSingleton(): void
    {
        $container = new Kernel(self::ROOT)->container();

        $context = $container->get(CompanyContext::class);

        self::assertInstanceOf(CompanyContext::class, $context);
        self::assertSame(1, $context->currentId());
        self::assertSame($context, $container->get(CompanyContext::class));
    }

    public function testTheDoctorReportsTheDatabaseOk(): void
    {
        $command = new Kernel(self::ROOT)->container()->get(DoctorCommand::class);

        self::assertInstanceOf(DoctorCommand::class, $command);

        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('OK server', $tester->getDisplay());
    }

    public function testTheDoctorSkipsGracefullyWithoutADsn(): void
    {
        putenv('LIMINAL_DSN');

        $command = new Kernel(self::ROOT)->container()->get(DoctorCommand::class);

        self::assertInstanceOf(DoctorCommand::class, $command);

        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('SKIP', $tester->getDisplay());
    }
}
