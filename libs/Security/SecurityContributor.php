<?php

declare(strict_types=1);

namespace Liminal\Lib\Security;

use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\RegistryCollection;

/**
 * lib/security: the authentication and scoping mechanics the authentication
 * module (phase 5) plugs into — database-backed sessions, password hashing,
 * the auth contracts, and the request middlewares that enforce deny-by-default
 * and the per-request company scope.
 */
final class SecurityContributor implements Contributor
{
    public const MIGRATION_NAMESPACE = 'Liminal\Lib\Security\Migrations';

    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(MigrationRegistry::class)
            ->add(self::MIGRATION_NAMESPACE, __DIR__ . '/Migrations');
    }
}
