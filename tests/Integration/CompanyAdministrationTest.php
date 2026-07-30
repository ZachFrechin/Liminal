<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Liminal\Kernel;
use Liminal\Lib\Module\ModuleManager;
use Liminal\Lib\System\Console\InstallCommand;
use Liminal\Module\Companies\Administration\CompanyAdministration;
use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Company creation against real SQL — the 5a obligation in service form: a
 * new company gets every installed module enabled in the same transaction as
 * its row, and the declared-but-not-installed ones are reported by name.
 *
 * Doubles as the production proof of the migration-less module: the companies
 * module installs, enables and overviews with a null migrationNamespace().
 */
#[CoversNothing]
final class CompanyAdministrationTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/../..';

    private Connection $dbal;

    private CompanyAdministration $admin;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping company administration test.');
        }

        $this->dbal = $this->connection();

        $this->dropAllTables($this->dbal);

        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);

        $container = new Kernel(self::ROOT)->container();

        $install = $container->get(InstallCommand::class);
        self::assertInstanceOf(InstallCommand::class, $install);
        self::assertSame(Command::SUCCESS, new CommandTester($install)->execute([]));

        $admin = $container->get(CompanyAdministration::class);
        self::assertInstanceOf(CompanyAdministration::class, $admin);
        $this->admin = $admin;
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    public function testAMigrationLessModuleLivesTheFullLifecycle(): void
    {
        // install seeded MAIN and enabled BOTH declared modules for it —
        // companies included, with zero migrations executed on its behalf.
        $manager = new Kernel(self::ROOT)->container()->get(ModuleManager::class);
        self::assertInstanceOf(ModuleManager::class, $manager);

        self::assertTrue($manager->isEnabled('companies', 1));

        $overview = [];

        foreach ($manager->overview() as $module) {
            $overview[$module->name] = $module->installedVersion;
        }

        self::assertSame('0.1.0', $overview['companies'] ?? null);
    }

    public function testCreatingACompanyEnablesEveryInstalledModuleAtomically(): void
    {
        $creation = $this->admin->create('ACME', 'Acme Corp');

        self::assertSame(['authentication', 'companies', 'thirdparty'], $creation->enabledModules);
        self::assertSame([], $creation->notInstalled);

        // The row and its enablements landed together.
        self::assertEquals('Acme Corp', $this->dbal->fetchOne(
            'SELECT name FROM core_company WHERE id = ?',
            [$creation->companyId],
        ));
        self::assertEquals(3, $this->dbal->fetchOne(
            'SELECT COUNT(*) FROM core_module_company WHERE company_id = ? AND enabled = 1',
            [$creation->companyId],
        ));
    }

    public function testADeclaredButNotInstalledModuleIsReportedNotEnabled(): void
    {
        // Simulate the operator who declared a module and never ran
        // module:install: erase the installation record.
        $this->dbal->executeStatement("DELETE FROM core_module_company WHERE module_id IN (SELECT id FROM core_module WHERE name = 'companies')");
        $this->dbal->executeStatement("DELETE FROM core_module WHERE name = 'companies'");

        $creation = $this->admin->create('ACME', 'Acme Corp');

        self::assertSame(['authentication', 'thirdparty'], $creation->enabledModules);
        self::assertSame(['companies'], $creation->notInstalled);

        self::assertEquals(2, $this->dbal->fetchOne(
            'SELECT COUNT(*) FROM core_module_company WHERE company_id = ?',
            [$creation->companyId],
        ));
    }

    public function testADuplicateCodeIsRefusedAndTheTransactionLeavesNothing(): void
    {
        try {
            $this->admin->create('MAIN', 'Impostor');
            self::fail('A duplicate company code must be refused.');
        } catch (UniqueConstraintViolationException) {
            // Nothing landed: one company, and only its original enablements.
        }

        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_company'));
        self::assertEquals(3, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_module_company'));
    }

    public function testRenameMovesTheNameAndOnlyTheName(): void
    {
        self::assertTrue($this->admin->rename(1, 'Main office'));
        self::assertFalse($this->admin->rename(999, 'Ghost'));

        $company = $this->admin->companyById(1);

        self::assertNotNull($company);
        self::assertSame('Main office', $company['name']);
        self::assertSame('MAIN', $company['code']);
    }

    public function testTheListReadsBackWhatWasCreated(): void
    {
        $this->admin->create('ACME', 'Acme Corp');

        $codes = array_map(
            static fn(array $company): string => $company['code'],
            $this->admin->listAll(),
        );

        self::assertSame(['ACME', 'MAIN'], $codes);
    }

    public function testTheCodePolicyIsWhatTheHandlersEnforce(): void
    {
        self::assertSame(1, preg_match(CompanyAdministration::CODE_PATTERN, 'ACME'));
        self::assertSame(1, preg_match(CompanyAdministration::CODE_PATTERN, 'MAIN'));
        self::assertSame(1, preg_match(CompanyAdministration::CODE_PATTERN, 'A1_B2'));
        self::assertSame(0, preg_match(CompanyAdministration::CODE_PATTERN, 'acme'));
        self::assertSame(0, preg_match(CompanyAdministration::CODE_PATTERN, '1ACME'));
        self::assertSame(0, preg_match(CompanyAdministration::CODE_PATTERN, ''));
        self::assertSame(0, preg_match(CompanyAdministration::CODE_PATTERN, str_repeat('A', 33)));
    }
}
