<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Liminal\Kernel;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Liminal\Module\Authentication\Exception\AuthenticationModuleException;
use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The administration service against real SQL: every guarantee the screens
 * lean on — the no-grant-anywhere flag, the admin anchor, the cascade shape of
 * a role deletion, the exact-set permission sync — proven on MariaDB, where
 * GROUP_CONCAT, EXISTS and ON DELETE actually run.
 */
#[CoversNothing]
final class UserAdministrationTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/../..';

    private Connection $dbal;

    private UserAdministration $admin;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping user administration test.');
        }

        $this->dbal = $this->connection();

        $this->dropAllTables($this->dbal);

        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);

        $runner = new Kernel(self::ROOT)->container()->get(MigrationRunner::class);
        self::assertInstanceOf(MigrationRunner::class, $runner);
        $runner->migrateToLatest();

        foreach ([['MAIN', 1], ['ACME', 2]] as [$code, $id]) {
            $this->dbal->insert('core_company', [
                'id' => $id,
                'code' => $code,
                'name' => $code . ' Company',
                'created_at' => '2026-07-30 00:00:00',
                'updated_at' => '2026-07-30 00:00:00',
            ]);
        }

        $this->admin = new UserAdministration(fn(): Connection => $this->dbal);
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    public function testTheListFlagsTheUserWhoCannotSignInAnywhere(): void
    {
        $granted = $this->admin->createUser('granted@liminal.test', 'x', 'Granted');
        $elsewhere = $this->admin->createUser('elsewhere@liminal.test', 'x', 'Elsewhere');
        $this->admin->createUser('stranded@liminal.test', 'x', 'Stranded');

        $role = $this->admin->createRole('member', 'Member', []);
        $this->admin->grant($granted, 1, $role);
        $this->admin->grant($elsewhere, 2, $role);

        $byEmail = [];

        foreach ($this->admin->listAll(1) as $row) {
            $byEmail[$row['email']] = $row;
        }

        // Granted in company 1: roles show, flag set.
        self::assertSame('member', $byEmail['granted@liminal.test']['roles']);
        self::assertTrue($byEmail['granted@liminal.test']['hasAnyGrant']);

        // Granted only elsewhere: empty roles HERE, but reachable somewhere.
        self::assertSame('', $byEmail['elsewhere@liminal.test']['roles']);
        self::assertTrue($byEmail['elsewhere@liminal.test']['hasAnyGrant']);

        // No grant anywhere: the state the screen must make visible.
        self::assertFalse($byEmail['stranded@liminal.test']['hasAnyGrant']);
    }

    public function testUserUpdatesTouchOnlyTheirTargetAndTheTimestamp(): void
    {
        $id = $this->admin->createUser('ada@liminal.test', 'original-hash', 'Ada');

        self::assertTrue($this->admin->updateDisplayName($id, 'Ada Lovelace'));
        self::assertTrue($this->admin->setActive($id, false));
        self::assertTrue($this->admin->replacePasswordHash($id, 'new-hash'));

        $user = $this->admin->userById($id);

        self::assertNotNull($user);
        self::assertSame('Ada Lovelace', $user['displayName']);
        self::assertFalse($user['active']);
        self::assertSame(
            'new-hash',
            $this->dbal->fetchOne('SELECT password_hash FROM core_user WHERE id = ?', [$id]),
        );

        // A missing user reports failure instead of pretending.
        self::assertFalse($this->admin->updateDisplayName(999, 'Ghost'));
        self::assertNull($this->admin->userById(999));
    }

    public function testDeletingAUserReportsWhetherAnythingWasThere(): void
    {
        $id = $this->admin->createUser('gone@liminal.test', 'x', 'Gone');

        self::assertTrue($this->admin->deleteUser($id));
        self::assertFalse($this->admin->deleteUser($id));
    }

    public function testGrantAndRevokeRoundTripPerCompany(): void
    {
        $user = $this->admin->createUser('ada@liminal.test', 'x', 'Ada');
        $role = $this->admin->createRole('member', 'Member', []);

        self::assertTrue($this->admin->grant($user, 1, $role));
        self::assertFalse($this->admin->grant($user, 1, $role));
        self::assertTrue($this->admin->grant($user, 2, $role));

        $grants = $this->admin->grantsForUser($user);

        // Ordered by company code: ACME before MAIN.
        self::assertCount(2, $grants);
        self::assertSame('ACME', $grants[0]['companyCode']);
        self::assertSame('MAIN', $grants[1]['companyCode']);
        self::assertSame('MAIN Company', $grants[1]['companyName']);
        self::assertSame('member', $grants[1]['roleCode']);

        // Revocation is per company: the other grant survives.
        self::assertTrue($this->admin->revoke($user, 1, $role));
        self::assertFalse($this->admin->revoke($user, 1, $role));
        self::assertCount(1, $this->admin->grantsForUser($user));
    }

    public function testCreateRoleRefusesADuplicateCodeAndLeavesNothingBehind(): void
    {
        $this->admin->createRole('auditor', 'Auditor', ['authentication.user.manage']);

        try {
            $this->admin->createRole('auditor', 'Impostor', ['authentication.user.manage']);
            self::fail('A duplicate role code must be refused.');
        } catch (UniqueConstraintViolationException) {
            // The transaction rolled back: one role, one permission set.
        }

        self::assertEquals(1, $this->dbal->fetchOne("SELECT COUNT(*) FROM core_role WHERE code = 'auditor'"));
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_role_permission'));
    }

    public function testUpdateRoleReplacesTheExactPermissionSet(): void
    {
        $role = $this->admin->createRole('editor', 'Editor', ['a.one', 'a.two']);

        $this->admin->updateRole($role, 'Content editor', ['a.two', 'a.three']);

        self::assertSame(['a.three', 'a.two'], $this->admin->permissionsOfRole($role));

        $record = $this->admin->roleById($role);

        self::assertNotNull($record);
        self::assertSame('Content editor', $record['label']);
        // The code never moves: there is no API to change it.
        self::assertSame('editor', $record['code']);
    }

    public function testDeletingARoleIsAMassRevocation(): void
    {
        $ada = $this->admin->createUser('ada@liminal.test', 'x', 'Ada');
        $bob = $this->admin->createUser('bob@liminal.test', 'x', 'Bob');
        $role = $this->admin->createRole('temp', 'Temporary', ['a.one']);
        $this->admin->grant($ada, 1, $role);
        $this->admin->grant($bob, 2, $role);

        self::assertTrue($this->admin->deleteRole($role));

        // Permissions and every holder's grant cascade away with the role.
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_role_permission'));
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_user_company_role'));
        self::assertFalse($this->admin->deleteRole($role));
    }

    public function testTheAdminRoleCannotBeDeleted(): void
    {
        $role = $this->admin->createRole(UserAdministration::ADMIN_ROLE_CODE, 'Administrator', []);

        $this->expectException(AuthenticationModuleException::class);

        $this->admin->deleteRole($role);
    }

    public function testRoleCountsFeedTheListScreen(): void
    {
        $user = $this->admin->createUser('ada@liminal.test', 'x', 'Ada');
        $role = $this->admin->createRole('member', 'Member', ['a.one', 'a.two']);
        $this->admin->grant($user, 1, $role);
        $this->admin->grant($user, 2, $role);
        $this->admin->createRole('empty', 'Empty', []);

        $byCode = [];

        foreach ($this->admin->listRoles() as $row) {
            $byCode[$row['code']] = $row;
        }

        self::assertSame(2, $byCode['member']['permissionCount']);
        self::assertSame(2, $byCode['member']['grantCount']);
        self::assertSame(0, $byCode['empty']['permissionCount']);
        self::assertSame(0, $byCode['empty']['grantCount']);
    }

    public function testCompaniesAreListedForTheSwitcherAndTheGrantForm(): void
    {
        self::assertSame(
            [
                ['id' => 2, 'code' => 'ACME', 'name' => 'ACME Company'],
                ['id' => 1, 'code' => 'MAIN', 'name' => 'MAIN Company'],
            ],
            $this->admin->listCompanies(),
        );
    }
}
