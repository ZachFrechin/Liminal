<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Administration;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Liminal\Module\Authentication\Exception\AuthenticationModuleException;
use Liminal\Module\Authentication\Security\DbalUserProvider;
use SensitiveParameter;

/**
 * The write side of user, grant and role administration, shared by the
 * bootstrap commands and the administration screens.
 *
 * Plain DBAL like every production write in the tree: administration is
 * cross-company by nature (the tables are deliberately unscoped), and the
 * deferred connection keeps the console safe — it resolves every registered
 * command eagerly, so a constructor-injected Connection would break `doctor`
 * on a checkout with no DSN.
 */
final readonly class UserAdministration
{
    /**
     * The role the bootstrap command mints and the role editor refuses to
     * delete: losing it would orphan every fresh installation's only key.
     */
    public const string ADMIN_ROLE_CODE = 'admin';

    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param Closure(): Connection $connection
     */
    public function __construct(private Closure $connection) {}

    // -- Users ---------------------------------------------------------------

    /**
     * @return int the new user's id
     *
     * @throws UniqueConstraintViolationException when the email is already taken
     */
    public function createUser(
        string $email,
        #[SensitiveParameter]
        string $passwordHash,
        string $displayName,
    ): int {
        $connection = ($this->connection)();
        $now = new DateTimeImmutable()->format(self::TIMESTAMP_FORMAT);

        $connection->insert('core_user', [
            'email' => DbalUserProvider::normalise($email),
            'password_hash' => $passwordHash,
            'display_name' => $displayName,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $connection->lastInsertId();
    }

    public function findUserIdByEmail(string $email): ?int
    {
        $id = ($this->connection)()->fetchOne(
            'SELECT id FROM core_user WHERE email = ?',
            [DbalUserProvider::normalise($email)],
        );

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array{id: int, email: string, displayName: string, active: bool}|null
     */
    public function userById(int $id): ?array
    {
        $row = ($this->connection)()->fetchAssociative(
            'SELECT id, email, display_name, is_active FROM core_user WHERE id = ?',
            [$id],
        );

        if ($row === false) {
            return null;
        }

        return [
            'id' => $this->intOf($row['id'] ?? null),
            'email' => $this->stringOf($row['email'] ?? null),
            'displayName' => $this->stringOf($row['display_name'] ?? null),
            'active' => (bool) ($row['is_active'] ?? false),
        ];
    }

    /**
     * Every user of the instance with their roles in ONE company — plus the
     * flag that makes the invisible state visible: a user with no grant in ANY
     * company has no accessible company and cannot sign in at all.
     *
     * @return list<array{id: int, email: string, displayName: string, active: bool, roles: string, hasAnyGrant: bool}>
     */
    public function listAll(int $companyId): array
    {
        $rows = ($this->connection)()->fetchAllAssociative(
            "SELECT u.id, u.email, u.display_name, u.is_active,
                    COALESCE(GROUP_CONCAT(r.code ORDER BY r.code SEPARATOR ', '), '') AS roles,
                    EXISTS(SELECT 1 FROM core_user_company_role a WHERE a.user_id = u.id) AS has_any_grant
             FROM core_user u
             LEFT JOIN core_user_company_role ucr ON ucr.user_id = u.id AND ucr.company_id = ?
             LEFT JOIN core_role r ON r.id = ucr.role_id
             GROUP BY u.id, u.email, u.display_name, u.is_active
             ORDER BY u.email",
            [$companyId],
        );

        $users = [];

        foreach ($rows as $row) {
            $users[] = [
                'id' => $this->intOf($row['id'] ?? null),
                'email' => $this->stringOf($row['email'] ?? null),
                'displayName' => $this->stringOf($row['display_name'] ?? null),
                'active' => (bool) ($row['is_active'] ?? false),
                'roles' => $this->stringOf($row['roles'] ?? null),
                'hasAnyGrant' => (bool) ($row['has_any_grant'] ?? false),
            ];
        }

        return $users;
    }

    /**
     * @return bool false when no such user exists
     */
    public function updateDisplayName(int $id, string $displayName): bool
    {
        return $this->updateUser($id, ['display_name' => $displayName]);
    }

    /**
     * Deactivation is the whole feature: is_active is filtered on login AND on
     * session hydration, so a live session ends at the user's next request.
     *
     * @return bool false when no such user exists
     */
    public function setActive(int $id, bool $active): bool
    {
        return $this->updateUser($id, ['is_active' => $active ? 1 : 0]);
    }

    /**
     * @return bool false when no such user exists
     */
    public function replacePasswordHash(
        int $id,
        #[SensitiveParameter]
        string $passwordHash,
    ): bool {
        return $this->updateUser($id, ['password_hash' => $passwordHash]);
    }

    /**
     * The schema owns the fallout: sessions and grants cascade away, audit
     * events keep their row and lose only the user id (SET NULL) — deleting an
     * account must not erase the record of what it did.
     *
     * @return bool false when no such user exists
     */
    public function deleteUser(int $id): bool
    {
        return ($this->connection)()->delete('core_user', ['id' => $id]) > 0;
    }

    // -- Grants --------------------------------------------------------------

    /**
     * Idempotent: granting a role someone already holds in that company is a
     * no-op rather than an error.
     *
     * @return bool true when the grant is new, false when it was already held
     */
    public function grant(int $userId, int $companyId, int $roleId): bool
    {
        $affected = ($this->connection)()->executeStatement(
            'INSERT INTO core_user_company_role (user_id, company_id, role_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = user_id',
            [$userId, $companyId, $roleId],
        );

        return (int) $affected > 0;
    }

    /**
     * @return bool false when the grant did not exist
     */
    public function revoke(int $userId, int $companyId, int $roleId): bool
    {
        return ($this->connection)()->delete('core_user_company_role', [
            'user_id' => $userId,
            'company_id' => $companyId,
            'role_id' => $roleId,
        ]) > 0;
    }

    /**
     * @return list<array{companyId: int, companyCode: string, companyName: string, roleId: int, roleCode: string, roleLabel: string}>
     */
    public function grantsForUser(int $userId): array
    {
        $rows = ($this->connection)()->fetchAllAssociative(
            'SELECT ucr.company_id, c.code AS company_code, c.name AS company_name,
                    ucr.role_id, r.code AS role_code, r.label AS role_label
             FROM core_user_company_role ucr
             JOIN core_company c ON c.id = ucr.company_id
             JOIN core_role r ON r.id = ucr.role_id
             WHERE ucr.user_id = ?
             ORDER BY c.code, r.code',
            [$userId],
        );

        $grants = [];

        foreach ($rows as $row) {
            $grants[] = [
                'companyId' => $this->intOf($row['company_id'] ?? null),
                'companyCode' => $this->stringOf($row['company_code'] ?? null),
                'companyName' => $this->stringOf($row['company_name'] ?? null),
                'roleId' => $this->intOf($row['role_id'] ?? null),
                'roleCode' => $this->stringOf($row['role_code'] ?? null),
                'roleLabel' => $this->stringOf($row['role_label'] ?? null),
            ];
        }

        return $grants;
    }

    // -- Companies (read-only: core_company belongs to the Database lib) -----

    public function companyExists(int $companyId): bool
    {
        return ($this->connection)()->fetchOne('SELECT id FROM core_company WHERE id = ?', [$companyId]) !== false;
    }

    /**
     * The names behind the ids the security path carries around — for the
     * account page's switcher and the grant form's select.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function listCompanies(): array
    {
        $rows = ($this->connection)()->fetchAllAssociative(
            'SELECT id, code, name FROM core_company ORDER BY code',
        );

        $companies = [];

        foreach ($rows as $row) {
            $companies[] = [
                'id' => $this->intOf($row['id'] ?? null),
                'code' => $this->stringOf($row['code'] ?? null),
                'name' => $this->stringOf($row['name'] ?? null),
            ];
        }

        return $companies;
    }

    // -- Roles ---------------------------------------------------------------

    /**
     * Creates the role if it is absent, holding exactly the permission codes
     * given. An EXISTING role is left alone: silently re-granting something an
     * administrator revoked would be worse than reporting the difference.
     *
     * @param list<string> $permissionCodes
     *
     * @return array{id: int, created: bool, missing: list<string>}
     */
    public function ensureRole(string $code, string $label, array $permissionCodes): array
    {
        $connection = ($this->connection)();

        $existing = $connection->fetchOne('SELECT id FROM core_role WHERE code = ?', [$code]);

        if (is_numeric($existing)) {
            return [
                'id' => (int) $existing,
                'created' => false,
                'missing' => $this->missingPermissions((int) $existing, $permissionCodes),
            ];
        }

        return ['id' => $this->insertRole($connection, $code, $label, $permissionCodes), 'created' => true, 'missing' => []];
    }

    /**
     * The screen's create: an existing code is an error the handler reports,
     * never a silent reuse.
     *
     * @param list<string> $permissionCodes
     *
     * @return int the new role's id
     *
     * @throws UniqueConstraintViolationException when the code is already taken
     */
    public function createRole(string $code, string $label, array $permissionCodes): int
    {
        return $this->insertRole(($this->connection)(), $code, $label, $permissionCodes);
    }

    /**
     * Replaces the label and the ENTIRE permission set in one transaction —
     * delete then insert, the same explicit-statements pattern the throttle
     * uses, because a clever differential upsert would be unreviewable.
     *
     * @param list<string> $permissionCodes
     */
    public function updateRole(int $roleId, string $label, array $permissionCodes): void
    {
        ($this->connection)()->transactional(static function (Connection $connection) use ($roleId, $label, $permissionCodes): void {
            $connection->update('core_role', ['label' => $label], ['id' => $roleId]);
            $connection->delete('core_role_permission', ['role_id' => $roleId]);

            foreach ($permissionCodes as $permission) {
                $connection->insert('core_role_permission', [
                    'role_id' => $roleId,
                    'permission_code' => $permission,
                ]);
            }
        });
    }

    /**
     * Deleting a role is a mass revocation: the grant rows of every holder in
     * every company cascade away with it.
     *
     * @return bool false when no such role exists
     *
     * @throws AuthenticationModuleException when the role is the protected admin anchor
     */
    public function deleteRole(int $roleId): bool
    {
        $connection = ($this->connection)();

        $code = $connection->fetchOne('SELECT code FROM core_role WHERE id = ?', [$roleId]);

        if ($code === self::ADMIN_ROLE_CODE) {
            throw AuthenticationModuleException::protectedRole(self::ADMIN_ROLE_CODE);
        }

        return $connection->delete('core_role', ['id' => $roleId]) > 0;
    }

    public function findRoleIdByCode(string $code): ?int
    {
        $id = ($this->connection)()->fetchOne('SELECT id FROM core_role WHERE code = ?', [$code]);

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array{id: int, code: string, label: string}|null
     */
    public function roleById(int $id): ?array
    {
        $row = ($this->connection)()->fetchAssociative(
            'SELECT id, code, label FROM core_role WHERE id = ?',
            [$id],
        );

        if ($row === false) {
            return null;
        }

        return [
            'id' => $this->intOf($row['id'] ?? null),
            'code' => $this->stringOf($row['code'] ?? null),
            'label' => $this->stringOf($row['label'] ?? null),
        ];
    }

    /**
     * @return list<array{id: int, code: string, label: string, permissionCount: int, grantCount: int}>
     */
    public function listRoles(): array
    {
        $rows = ($this->connection)()->fetchAllAssociative(
            'SELECT r.id, r.code, r.label,
                    (SELECT COUNT(*) FROM core_role_permission rp WHERE rp.role_id = r.id) AS permission_count,
                    (SELECT COUNT(*) FROM core_user_company_role ucr WHERE ucr.role_id = r.id) AS grant_count
             FROM core_role r
             ORDER BY r.code',
        );

        $roles = [];

        foreach ($rows as $row) {
            $roles[] = [
                'id' => $this->intOf($row['id'] ?? null),
                'code' => $this->stringOf($row['code'] ?? null),
                'label' => $this->stringOf($row['label'] ?? null),
                'permissionCount' => $this->intOf($row['permission_count'] ?? null),
                'grantCount' => $this->intOf($row['grant_count'] ?? null),
            ];
        }

        return $roles;
    }

    /**
     * @return list<string> the permission codes the role holds, as stored
     */
    public function permissionsOfRole(int $roleId): array
    {
        $codes = [];

        $rows = ($this->connection)()->fetchAllAssociative(
            'SELECT permission_code FROM core_role_permission WHERE role_id = ? ORDER BY permission_code',
            [$roleId],
        );

        foreach ($rows as $row) {
            $code = $row['permission_code'] ?? null;

            if (is_string($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    // -- Internals -----------------------------------------------------------

    /**
     * @param array<string, int|string> $fields
     */
    private function updateUser(int $id, array $fields): bool
    {
        $fields['updated_at'] = new DateTimeImmutable()->format(self::TIMESTAMP_FORMAT);

        return ($this->connection)()->update('core_user', $fields, ['id' => $id]) > 0;
    }

    /**
     * @param list<string> $permissionCodes
     */
    private function insertRole(Connection $connection, string $code, string $label, array $permissionCodes): int
    {
        return (int) $connection->transactional(static function (Connection $connection) use ($code, $label, $permissionCodes): int {
            $connection->insert('core_role', ['code' => $code, 'label' => $label]);
            $roleId = (int) $connection->lastInsertId();

            foreach ($permissionCodes as $permission) {
                $connection->insert('core_role_permission', [
                    'role_id' => $roleId,
                    'permission_code' => $permission,
                ]);
            }

            return $roleId;
        });
    }

    /**
     * @param list<string> $permissionCodes
     *
     * @return list<string> declared codes the role does not carry
     */
    private function missingPermissions(int $roleId, array $permissionCodes): array
    {
        return array_values(array_diff($permissionCodes, $this->permissionsOfRole($roleId)));
    }

    private function stringOf(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
