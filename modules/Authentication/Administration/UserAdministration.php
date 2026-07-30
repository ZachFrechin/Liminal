<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Administration;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Liminal\Module\Authentication\Security\DbalUserProvider;
use SensitiveParameter;

/**
 * The write side of user administration, used by the bootstrap commands and by
 * the read-only screen the next phase turns into a CRUD.
 *
 * Deferred connection, like everything the console can reach: the console
 * resolves every registered command eagerly, so a constructor-injected
 * Connection would break `doctor` on a checkout with no DSN.
 */
final readonly class UserAdministration
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param Closure(): Connection $connection
     */
    public function __construct(private Closure $connection) {}

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

        $connection->insert('core_role', ['code' => $code, 'label' => $label]);
        $roleId = (int) $connection->lastInsertId();

        foreach ($permissionCodes as $permission) {
            $connection->insert('core_role_permission', [
                'role_id' => $roleId,
                'permission_code' => $permission,
            ]);
        }

        return ['id' => $roleId, 'created' => true, 'missing' => []];
    }

    public function findRoleIdByCode(string $code): ?int
    {
        $id = ($this->connection)()->fetchOne('SELECT id FROM core_role WHERE code = ?', [$code]);

        return is_numeric($id) ? (int) $id : null;
    }

    public function companyExists(int $companyId): bool
    {
        return ($this->connection)()->fetchOne('SELECT id FROM core_company WHERE id = ?', [$companyId]) !== false;
    }

    /**
     * Idempotent: granting a role someone already holds in that company is a
     * no-op rather than an error.
     */
    public function grant(int $userId, int $companyId, int $roleId): void
    {
        ($this->connection)()->executeStatement(
            'INSERT INTO core_user_company_role (user_id, company_id, role_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = user_id',
            [$userId, $companyId, $roleId],
        );
    }

    /**
     * The read the account screen's sibling page shows: every user with the
     * roles they hold in one company.
     *
     * @return list<array{email: string, displayName: string, active: bool, roles: string}>
     */
    public function listForCompany(int $companyId): array
    {
        $rows = ($this->connection)()->fetchAllAssociative(
            "SELECT u.email, u.display_name, u.is_active,
                    COALESCE(GROUP_CONCAT(r.code ORDER BY r.code SEPARATOR ', '), '') AS roles
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
                'email' => is_string($row['email'] ?? null) ? $row['email'] : '',
                'displayName' => is_string($row['display_name'] ?? null) ? $row['display_name'] : '',
                'active' => (bool) ($row['is_active'] ?? false),
                'roles' => is_string($row['roles'] ?? null) ? $row['roles'] : '',
            ];
        }

        return $users;
    }

    /**
     * @param list<string> $permissionCodes
     *
     * @return list<string> declared codes the role does not carry
     */
    private function missingPermissions(int $roleId, array $permissionCodes): array
    {
        $held = [];

        $rows = ($this->connection)()->fetchAllAssociative(
            'SELECT permission_code FROM core_role_permission WHERE role_id = ?',
            [$roleId],
        );

        foreach ($rows as $row) {
            $code = $row['permission_code'] ?? null;

            if (is_string($code)) {
                $held[] = $code;
            }
        }

        return array_values(array_diff($permissionCodes, $held));
    }
}
