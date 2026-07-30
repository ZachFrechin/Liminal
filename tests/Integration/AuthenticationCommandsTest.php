<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Liminal\Kernel;
use Liminal\Lib\System\Console\InstallCommand;
use Liminal\Module\Authentication\Console\RoleGrantCommand;
use Liminal\Module\Authentication\Console\UserCreateCommand;
use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The terminal half of the bootstrap: after `install`, one command mints the
 * administrator — user, admin role holding every declared permission, grant —
 * and a second one wires further grants. Everything here runs the real
 * application root, prompts included.
 */
#[CoversNothing]
final class AuthenticationCommandsTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/../..';

    private Connection $dbal;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping authentication command test.');
        }

        $this->dbal = $this->connection();

        $this->dropAllTables($this->dbal);

        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);

        $install = new Kernel(self::ROOT)->container()->get(InstallCommand::class);
        self::assertInstanceOf(InstallCommand::class, $install);
        self::assertSame(Command::SUCCESS, new CommandTester($install)->execute([]));
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    public function testUserCreateMintsTheAdministratorEndToEnd(): void
    {
        $tester = $this->userCreate();
        $tester->setInputs(['s3cret-enough', 's3cret-enough']);

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => 'ada@liminal.test']));
        self::assertStringContainsString('Created the "admin" role', $tester->getDisplay());

        // The user exists, active, named after the email's local part, and the
        // stored hash verifies the prompted password.
        $user = $this->dbal->fetchAssociative(
            'SELECT id, display_name, is_active, password_hash FROM core_user WHERE email = ?',
            ['ada@liminal.test'],
        );

        self::assertNotFalse($user);
        self::assertSame('ada', $user['display_name']);
        self::assertEquals(1, $user['is_active']);
        self::assertIsString($user['password_hash']);
        self::assertTrue(password_verify('s3cret-enough', $user['password_hash']));

        // The admin role holds every declared permission…
        self::assertEquals(
            $this->dbal->fetchOne('SELECT COUNT(*) FROM core_role_permission'),
            $this->dbal->fetchOne(
                "SELECT COUNT(*) FROM core_role_permission rp
                 JOIN core_role r ON r.id = rp.role_id
                 WHERE r.code = 'admin'",
            ),
        );
        self::assertEquals(1, $this->dbal->fetchOne(
            "SELECT COUNT(*) FROM core_role_permission rp
             JOIN core_role r ON r.id = rp.role_id
             WHERE r.code = 'admin' AND rp.permission_code = 'authentication.user.manage'",
        ));

        // …and the grant binds the two in company 1.
        self::assertEquals(1, $this->dbal->fetchOne(
            "SELECT COUNT(*) FROM core_user_company_role ucr
             JOIN core_user u ON u.id = ucr.user_id
             JOIN core_role r ON r.id = ucr.role_id
             WHERE u.email = 'ada@liminal.test' AND r.code = 'admin' AND ucr.company_id = 1",
        ));
    }

    /**
     * An admin role that already exists is left alone; what it lacks against
     * the declared catalogue is reported, never silently repaired — the gap
     * may be a deliberate revocation.
     */
    public function testAnExistingAdminRoleIsLeftAloneAndItsDriftReported(): void
    {
        $first = $this->userCreate();
        $first->setInputs(['s3cret-enough', 's3cret-enough']);
        self::assertSame(Command::SUCCESS, $first->execute(['email' => 'ada@liminal.test']));

        $this->dbal->executeStatement(
            "DELETE FROM core_role_permission WHERE permission_code = 'authentication.user.manage'",
        );

        $second = $this->userCreate();
        $second->setInputs(['0ther-secret', '0ther-secret']);

        self::assertSame(Command::SUCCESS, $second->execute(['email' => 'bob@liminal.test']));
        self::assertStringContainsString('authentication.user.manage', $second->getDisplay());
        self::assertStringContainsString('Left untouched', $second->getDisplay());
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_role_permission'));
    }

    public function testAShortPasswordIsRefusedAndNothingIsCreated(): void
    {
        $tester = $this->userCreate();
        $tester->setInputs(['short']);

        self::assertSame(Command::FAILURE, $tester->execute(['email' => 'ada@liminal.test']));
        self::assertStringContainsString('at least 8 characters', $tester->getDisplay());
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_user'));
    }

    public function testAMismatchedConfirmationIsRefusedAndNothingIsCreated(): void
    {
        $tester = $this->userCreate();
        $tester->setInputs(['s3cret-enough', 's3cret-other']);

        self::assertSame(Command::FAILURE, $tester->execute(['email' => 'ada@liminal.test']));
        self::assertStringContainsString('do not match', $tester->getDisplay());
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_user'));
    }

    /**
     * No --password option exists, and a non-interactive run is refused rather
     * than served by an invented secret: the refusal is the feature.
     */
    public function testANonInteractiveRunIsRefusedBeforeTouchingAnything(): void
    {
        $tester = $this->userCreate();

        self::assertSame(
            Command::FAILURE,
            $tester->execute(['email' => 'ada@liminal.test'], ['interactive' => false]),
        );
        self::assertStringContainsString('cannot run non-interactively', $tester->getDisplay());
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_user'));
    }

    public function testADuplicateEmailFailsPolitely(): void
    {
        $first = $this->userCreate();
        $first->setInputs(['s3cret-enough', 's3cret-enough']);
        self::assertSame(Command::SUCCESS, $first->execute(['email' => 'ada@liminal.test']));

        $again = $this->userCreate();
        $again->setInputs(['s3cret-enough', 's3cret-enough']);

        self::assertSame(Command::FAILURE, $again->execute(['email' => 'Ada@Liminal.test']));
        self::assertStringContainsString('already exists', $again->getDisplay());
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_user'));
    }

    public function testWithoutADsnUserCreateFailsWithTheRemedyNotAStackTrace(): void
    {
        putenv('LIMINAL_DSN');

        $tester = $this->userCreate();
        $tester->setInputs(['s3cret-enough', 's3cret-enough']);

        self::assertSame(Command::FAILURE, $tester->execute(['email' => 'ada@liminal.test']));
        self::assertStringContainsString('Cannot reach the database', $tester->getDisplay());
    }

    public function testRoleGrantWiresAnExistingRoleAndReportsARepeatHonestly(): void
    {
        $create = $this->userCreate();
        $create->setInputs(['s3cret-enough', 's3cret-enough']);
        self::assertSame(Command::SUCCESS, $create->execute(['email' => 'ada@liminal.test']));

        $this->dbal->insert('core_user', [
            'email' => 'bob@liminal.test',
            'password_hash' => password_hash('irrelevant', PASSWORD_BCRYPT, ['cost' => 4]),
            'display_name' => 'Bob',
            'is_active' => 1,
            'created_at' => '2026-07-30 00:00:00',
            'updated_at' => '2026-07-30 00:00:00',
        ]);

        $grant = $this->roleGrant();

        self::assertSame(Command::SUCCESS, $grant->execute(['email' => 'bob@liminal.test', 'role' => 'admin']));
        self::assertStringContainsString('Granted "admin" to bob@liminal.test in company 1', $grant->getDisplay());

        // Granting again changes nothing and says so.
        $again = $this->roleGrant();

        self::assertSame(Command::SUCCESS, $again->execute(['email' => 'bob@liminal.test', 'role' => 'admin']));
        self::assertStringContainsString('already holds', $again->getDisplay());
        self::assertEquals(1, $this->dbal->fetchOne(
            "SELECT COUNT(*) FROM core_user_company_role ucr
             JOIN core_user u ON u.id = ucr.user_id
             WHERE u.email = 'bob@liminal.test'",
        ));
    }

    /**
     * The grant command creates nothing: each absent operand is a named error,
     * because inventing users, roles or companies would paper over typos with
     * security state.
     */
    public function testRoleGrantNamesWhateverOperandIsAbsent(): void
    {
        $create = $this->userCreate();
        $create->setInputs(['s3cret-enough', 's3cret-enough']);
        self::assertSame(Command::SUCCESS, $create->execute(['email' => 'ada@liminal.test']));

        $noUser = $this->roleGrant();
        self::assertSame(Command::FAILURE, $noUser->execute(['email' => 'ghost@liminal.test', 'role' => 'admin']));
        self::assertStringContainsString('No user with the email "ghost@liminal.test"', $noUser->getDisplay());

        $noRole = $this->roleGrant();
        self::assertSame(Command::FAILURE, $noRole->execute(['email' => 'ada@liminal.test', 'role' => 'butler']));
        self::assertStringContainsString('No role with the code "butler"', $noRole->getDisplay());

        $noCompany = $this->roleGrant();
        self::assertSame(Command::FAILURE, $noCompany->execute([
            'email' => 'ada@liminal.test',
            'role' => 'admin',
            '--company' => '9',
        ]));
        self::assertStringContainsString('Company 9 does not exist', $noCompany->getDisplay());
    }

    private function userCreate(): CommandTester
    {
        $command = new Kernel(self::ROOT)->container()->get(UserCreateCommand::class);

        self::assertInstanceOf(UserCreateCommand::class, $command);

        return new CommandTester($command);
    }

    private function roleGrant(): CommandTester
    {
        $command = new Kernel(self::ROOT)->container()->get(RoleGrantCommand::class);

        self::assertInstanceOf(RoleGrantCommand::class, $command);

        return new CommandTester($command);
    }
}
