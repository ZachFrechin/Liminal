<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Security;

use Closure;
use Doctrine\DBAL\Connection;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Security\Contract\AuthenticatedUser;
use Liminal\Lib\Security\Contract\PermissionResolver;

/**
 * Answers a permission from the roles the user holds IN THAT COMPANY — the
 * whole of RBAC-per-company, one query.
 *
 * Mutable because memoisation is the point (the Translator precedent): the menu
 * asks the Gate once per item, so without a cache a twenty-item menu would cost
 * twenty queries. The cache is invalidated on the hook that already exists —
 * CompanyContext::onSwitch, which the switch middleware fires unconditionally on
 * every request, so this is a per-request cache even in a worker runtime, using
 * the very hook the EntityManager uses to evict its identity map.
 */
final class DbalPermissionResolver implements PermissionResolver
{
    /** @var array<string, list<string>> "userId:companyId" => permission codes */
    private array $grants = [];

    /**
     * @param Closure(): Connection $connection
     */
    public function __construct(
        private readonly Closure $connection,
        CompanyContext $context,
    ) {
        $context->onSwitch($this->forget(...));
    }

    public function allows(AuthenticatedUser $user, string $permission, int $companyId): bool
    {
        return in_array($permission, $this->grantsFor($user->id(), $companyId), true);
    }

    /**
     * @return list<string>
     */
    private function grantsFor(int $userId, int $companyId): array
    {
        $key = $userId . ':' . $companyId;

        if (array_key_exists($key, $this->grants)) {
            return $this->grants[$key];
        }

        $codes = [];

        $rows = ($this->connection)()->fetchAllAssociative(
            'SELECT rp.permission_code
             FROM core_user_company_role ucr
             JOIN core_role_permission rp ON rp.role_id = ucr.role_id
             WHERE ucr.user_id = ? AND ucr.company_id = ?',
            [$userId, $companyId],
        );

        foreach ($rows as $row) {
            $code = $row['permission_code'] ?? null;

            if (is_string($code)) {
                $codes[] = $code;
            }
        }

        return $this->grants[$key] = array_values(array_unique($codes));
    }

    private function forget(): void
    {
        $this->grants = [];
    }
}
