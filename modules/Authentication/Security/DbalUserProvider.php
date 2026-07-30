<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Security;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Liminal\Lib\Security\Authentication\LoginCandidate;
use Liminal\Lib\Security\Contract\AuthenticatedUser;
use Liminal\Lib\Security\Contract\UserProvider;
use SensitiveParameter;

/**
 * Reads users straight through DBAL, never the ORM.
 *
 * The rule this obeys: request-path security reads never go through Doctrine.
 * The company switch clears the EntityManager on every request, one middleware
 * after authentication, so an entity read here would be detached before any
 * template could use it. The Doctrine entities stay registered for schema
 * tooling and future ORM consumers; administration went DBAL too.
 *
 * Both lookups filter on is_active, which is the entire deactivation feature:
 * forLogin() returning null is indistinguishable from an unknown email, and
 * byId() returning null makes the authentication middleware log out the live
 * session on its next request.
 */
final readonly class DbalUserProvider implements UserProvider
{
    /**
     * @param Closure(): Connection $connection deferred: commands resolve eagerly
     */
    public function __construct(private Closure $connection) {}

    public function byId(int $id): ?AuthenticatedUser
    {
        return $this->identityFrom(
            ($this->connection)()->fetchAllAssociative(
                'SELECT u.id, u.email, u.display_name, ucr.company_id
                 FROM core_user u
                 LEFT JOIN core_user_company_role ucr ON ucr.user_id = u.id
                 WHERE u.id = ? AND u.is_active = 1',
                [$id],
            ),
        );
    }

    public function forLogin(string $identifier): ?LoginCandidate
    {
        $rows = ($this->connection)()->fetchAllAssociative(
            'SELECT u.id, u.email, u.display_name, u.password_hash, ucr.company_id
             FROM core_user u
             LEFT JOIN core_user_company_role ucr ON ucr.user_id = u.id
             WHERE u.email = ? AND u.is_active = 1',
            [self::normalise($identifier)],
        );

        $identity = $this->identityFrom($rows);
        $hash = $rows[0]['password_hash'] ?? null;

        if ($identity === null || !is_string($hash)) {
            return null;
        }

        return new LoginCandidate($identity, $hash);
    }

    public function rehash(int $id, #[SensitiveParameter] string $hash): void
    {
        ($this->connection)()->executeStatement(
            'UPDATE core_user SET password_hash = ?, updated_at = ? WHERE id = ?',
            [$hash, new DateTimeImmutable()->format('Y-m-d H:i:s'), $id],
        );
    }

    /**
     * Normalised in PHP rather than leaning on the column's collation: a
     * case-insensitive collation would make uniqueness depend on server
     * configuration instead of on us.
     */
    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @param list<array<string, mixed>> $rows one row per grant; none when the user has no grant
     */
    private function identityFrom(array $rows): ?Identity
    {
        $first = $rows[0] ?? null;

        if ($first === null || !is_numeric($first['id'] ?? null)) {
            return null;
        }

        $companyIds = [];

        foreach ($rows as $row) {
            $companyId = $row['company_id'] ?? null;

            if (is_numeric($companyId)) {
                $companyIds[] = (int) $companyId;
            }
        }

        return new Identity(
            (int) $first['id'],
            is_string($first['email'] ?? null) ? $first['email'] : '',
            is_string($first['display_name'] ?? null) ? $first['display_name'] : '',
            array_values(array_unique($companyIds)),
        );
    }
}
