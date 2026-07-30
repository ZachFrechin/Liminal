<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Liminal\Kernel;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Module\Authentication\AuthenticationModule;
use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The authentication schema against real MariaDB: the ordering guarantee the
 * cross-lib foreign key depends on, and the cascades that decide what survives
 * a deletion.
 */
#[CoversNothing]
final class AuthenticationSchemaTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const COMPANY = 1;

    private Connection $dbal;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping authentication schema test.');
        }

        $this->dbal = $this->connection();
        $this->dropAllTables($this->dbal);

        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    /**
     * The module's migration adds a foreign key to a table the SECURITY lib
     * owns, so it must run after it. Under the default comparator that held
     * only because 'Lib' sorts before 'Module'; the contribution-order
     * comparator makes it a guarantee, and this asserts the guarantee.
     */
    public function testTheSecurityNamespaceMigratesBeforeTheModuleThatAddsItsForeignKey(): void
    {
        $executed = $this->migrate();

        $securityIndex = null;
        $moduleIndex = null;

        foreach ($executed as $index => $version) {
            if (str_starts_with($version, 'Liminal\Lib\Security\Migrations')) {
                $securityIndex = $index;
            }

            if (str_starts_with($version, AuthenticationModule::MIGRATION_NAMESPACE)) {
                $moduleIndex = $index;
            }
        }

        self::assertNotNull($securityIndex);
        self::assertNotNull($moduleIndex);
        self::assertLessThan($moduleIndex, $securityIndex);
    }

    public function testDeletingAUserCascadesTheirSessionsAndGrants(): void
    {
        $this->migrate();
        $this->seedCompany();
        $userId = $this->seedUser();

        $this->dbal->insert('core_session', [
            'id' => hash('sha256', 'a-session'),
            'user_id' => $userId,
            'company_id' => self::COMPANY,
            'payload' => '{}',
            'created_at' => '2026-07-30 00:00:00',
            'last_seen_at' => '2026-07-30 00:00:00',
            'expires_at' => '2026-07-31 00:00:00',
        ]);
        $this->grantRole($userId, $this->seedRole());

        $this->dbal->executeStatement('DELETE FROM core_user WHERE id = ?', [$userId]);

        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_session'));
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_user_company_role'));
    }

    /**
     * The audit trail must outlive the account: if it cascaded, deleting a user
     * would erase the evidence of what that user did.
     */
    public function testDeletingAUserKeepsTheAuditEventAndForgetsOnlyTheUserId(): void
    {
        $this->migrate();
        $this->seedCompany();
        $userId = $this->seedUser();

        $this->dbal->insert('core_auth_event', [
            'occurred_at' => '2026-07-30 00:00:00',
            'event' => 'login.granted',
            'user_id' => $userId,
            'identifier' => 'admin@example.test',
            'ip_address' => '203.0.113.7',
            'user_agent' => 'PHPUnit',
        ]);

        $this->dbal->executeStatement('DELETE FROM core_user WHERE id = ?', [$userId]);

        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_auth_event'));
        self::assertNull($this->dbal->fetchOne('SELECT user_id FROM core_auth_event'));
    }

    public function testDeletingACompanyCascadesItsGrantsOnly(): void
    {
        $this->migrate();
        $this->seedCompany();
        $userId = $this->seedUser();
        $this->grantRole($userId, $this->seedRole());

        $this->dbal->executeStatement('DELETE FROM core_company WHERE id = ?', [self::COMPANY]);

        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_user_company_role'));
        // The user and the role are global; only the grant was per-company.
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_user'));
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_role'));
    }

    public function testDeletingARoleCascadesItsPermissionsAndGrants(): void
    {
        $this->migrate();
        $this->seedCompany();
        $roleId = $this->seedRole();
        $this->grantRole($this->seedUser(), $roleId);

        $this->dbal->executeStatement('DELETE FROM core_role WHERE id = ?', [$roleId]);

        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_role_permission'));
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_user_company_role'));
    }

    /**
     * The 5b upgrade path: an instance whose admin role predates the
     * role.manage permission gets it granted by migration — otherwise the new
     * role screens would be unreachable on every upgraded instance, with no
     * web recourse. Replaying the migration against a role that already holds
     * the code adds nothing.
     */
    public function testTheUpgradeMigrationGrantsRoleManageToAnExistingAdminRole(): void
    {
        $this->migrate();

        // Rewind to the pre-5b state: an admin role holding only user.manage,
        // and the upgrade migration not yet recorded.
        $this->dbal->insert('core_role', ['code' => 'admin', 'label' => 'Administrator']);
        $roleId = (int) $this->dbal->lastInsertId();
        $this->dbal->insert('core_role_permission', [
            'role_id' => $roleId,
            'permission_code' => 'authentication.user.manage',
        ]);
        $this->dbal->executeStatement(
            "DELETE FROM core_migration_version WHERE version LIKE '%Version20260730000001'",
        );

        $this->migrate();

        self::assertEquals(1, $this->dbal->fetchOne(
            "SELECT COUNT(*) FROM core_role_permission
             WHERE role_id = ? AND permission_code = 'authentication.role.manage'",
            [$roleId],
        ));

        // Replaying against an already-granted role is a no-op, not a dupe.
        $this->dbal->executeStatement(
            "DELETE FROM core_migration_version WHERE version LIKE '%Version20260730000001'",
        );
        $this->migrate();

        self::assertEquals(2, $this->dbal->fetchOne(
            'SELECT COUNT(*) FROM core_role_permission WHERE role_id = ?',
            [$roleId],
        ));
    }

    public function testTheEmailUniqueIndexHolds(): void
    {
        $this->migrate();
        $this->seedUser();

        $this->expectException(UniqueConstraintViolationException::class);

        $this->seedUser();
    }

    /**
     * @return list<string>
     */
    private function migrate(): array
    {
        $runner = new Kernel(self::ROOT)->container()->get(MigrationRunner::class);

        self::assertInstanceOf(MigrationRunner::class, $runner);

        return $runner->migrateToLatest();
    }

    private function seedCompany(): void
    {
        $this->dbal->insert('core_company', [
            'id' => self::COMPANY,
            'code' => 'MAIN',
            'name' => 'MAIN',
            'created_at' => '2026-07-30 00:00:00',
            'updated_at' => '2026-07-30 00:00:00',
        ]);
    }

    private function seedUser(): int
    {
        $now = new DateTimeImmutable()->format('Y-m-d H:i:s');

        $this->dbal->insert('core_user', [
            'email' => 'admin@example.test',
            'password_hash' => 'irrelevant',
            'display_name' => 'Admin',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->dbal->lastInsertId();
    }

    private function seedRole(): int
    {
        $this->dbal->insert('core_role', ['code' => 'admin', 'label' => 'Administrator']);
        $roleId = (int) $this->dbal->lastInsertId();

        $this->dbal->insert('core_role_permission', [
            'role_id' => $roleId,
            'permission_code' => 'authentication.user.manage',
        ]);

        return $roleId;
    }

    private function grantRole(int $userId, int $roleId): void
    {
        $this->dbal->insert('core_user_company_role', [
            'user_id' => $userId,
            'company_id' => self::COMPANY,
            'role_id' => $roleId,
        ]);
    }
}
