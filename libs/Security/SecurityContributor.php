<?php

declare(strict_types=1);

namespace Liminal\Lib\Security;

use Liminal\Config\Configuration;
use Liminal\Lib\Database\DeferredConnection;
use Liminal\Lib\Security\Console\SessionGcCommand;
use Liminal\Lib\Security\Session\SessionManager;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Registry\CommandRegistry;
use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\MiddlewareRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\RegistryCollection;
use Psr\Container\ContainerInterface;

/**
 * lib/security: the authentication and scoping mechanics the authentication
 * module (phase 5) plugs into — database-backed sessions, password hashing,
 * the auth contracts, and the request middlewares that enforce
 * deny-by-default and the per-request company scope.
 */
final class SecurityContributor implements Contributor, DefinitionProvider
{
    public const MIGRATION_NAMESPACE = 'Liminal\Lib\Security\Migrations';

    /** Sessions load before anything else in the request can want them. */
    public const int SESSION_PRIORITY = -900;

    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(MigrationRegistry::class)
            ->add(self::MIGRATION_NAMESPACE, __DIR__ . '/Migrations');

        $registries->get(MiddlewareRegistry::class)
            ->add(SessionMiddleware::class, self::SESSION_PRIORITY);

        $registries->get(CommandRegistry::class)
            ->add(SessionGcCommand::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function definitions(Configuration $config): array
    {
        return [
            // Deferred connection: session:gc is a console command, and the
            // console resolves every command eagerly (CONVENTIONS).
            SessionManager::class => static fn(ContainerInterface $container): SessionManager
                => new SessionManager(
                    DeferredConnection::resolver($container),
                    $config->string('security.session.cookie'),
                    $config->int('security.session.idle_ttl_seconds'),
                    $config->int('security.session.absolute_ttl_seconds'),
                    $config->bool('security.session.secure'),
                    $config->int('security.session.gc_percent'),
                ),
        ];
    }
}
