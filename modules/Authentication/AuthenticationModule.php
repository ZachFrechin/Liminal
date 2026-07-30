<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication;

use Liminal\Config\Configuration;
use Liminal\Lib\Database\DeferredConnection;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Security\Contract\AuthEventLog;
use Liminal\Lib\Security\Contract\LoginThrottle;
use Liminal\Lib\Security\Contract\PermissionResolver;
use Liminal\Lib\Security\Contract\UserProvider;
use Liminal\Module\Authentication\Security\DbalAuthEventLog;
use Liminal\Module\Authentication\Security\DbalLoginThrottle;
use Liminal\Module\Authentication\Security\DbalPermissionResolver;
use Liminal\Module\Authentication\Security\DbalUserProvider;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\Contract\Module;
use Liminal\Registry\EntityRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\RegistryCollection;
use Psr\Container\ContainerInterface;

/**
 * The authentication module: users, roles granted per company, and the
 * implementations the security lib has been shipping inert defaults for.
 *
 * It replaces four bindings through the ordinary last-wins definition layering
 * every module gets — no special case, no hook, no magic name.
 */
final class AuthenticationModule implements Module, DefinitionProvider
{
    public const MIGRATION_NAMESPACE = 'Liminal\Module\Authentication\Migrations';

    public const ENTITY_NAMESPACE = 'Liminal\Module\Authentication\Entity';

    public function name(): string
    {
        return 'authentication';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function migrationNamespace(): string
    {
        return self::MIGRATION_NAMESPACE;
    }

    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(MigrationRegistry::class)
            ->add(self::MIGRATION_NAMESPACE, __DIR__ . '/Migrations');

        // Registered for the administration screens phase 5b builds; the
        // request-path security reads never touch the ORM (see DbalUserProvider).
        $registries->get(EntityRegistry::class)
            ->add(self::ENTITY_NAMESPACE, __DIR__ . '/Entity');
    }

    /**
     * @return array<string, mixed>
     */
    public function definitions(Configuration $config): array
    {
        return [
            UserProvider::class => static fn(ContainerInterface $container): UserProvider
                => new DbalUserProvider(DeferredConnection::resolver($container)),

            PermissionResolver::class => static fn(
                ContainerInterface $container,
                CompanyContext $context,
            ): PermissionResolver => new DbalPermissionResolver(
                DeferredConnection::resolver($container),
                $context,
            ),

            // The module reads the security lib's config section because it
            // implements the lib's contract: the thresholds belong to the
            // mechanism, not to this module's identity.
            LoginThrottle::class => static fn(ContainerInterface $container): LoginThrottle
                => new DbalLoginThrottle(
                    DeferredConnection::resolver($container),
                    $config->int('security.login_throttle.max_failures'),
                    $config->int('security.login_throttle.address_max_failures'),
                    $config->int('security.login_throttle.window_seconds'),
                    $config->int('security.login_throttle.lockout_seconds'),
                ),

            AuthEventLog::class => static fn(ContainerInterface $container): AuthEventLog
                => new DbalAuthEventLog(DeferredConnection::resolver($container)),
        ];
    }
}
