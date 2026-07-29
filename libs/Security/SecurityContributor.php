<?php

declare(strict_types=1);

namespace Liminal\Lib\Security;

use Liminal\Config\Configuration;
use Liminal\Lib\Database\DeferredConnection;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Security\Authentication\AuthenticationMiddleware;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Lib\Security\Authentication\NullUserProvider;
use Liminal\Lib\Security\Authorization\DenyAllResolver;
use Liminal\Lib\Security\Console\SessionGcCommand;
use Liminal\Lib\Security\Contract\PermissionResolver;
use Liminal\Lib\Security\Contract\UserProvider;
use Liminal\Lib\Security\Csrf\CsrfMiddleware;
use Liminal\Lib\Security\Scope\CompanySwitchMiddleware;
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

    /** After the router (needs the matched route's public flag). */
    public const int AUTHENTICATION_PRIORITY = 100;

    /** After auth, so a protected-route POST 401s before its token is checked. */
    public const int CSRF_PRIORITY = 200;

    /** After auth: the scope follows the authenticated user. */
    public const int COMPANY_SWITCH_PRIORITY = 300;

    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(MigrationRegistry::class)
            ->add(self::MIGRATION_NAMESPACE, __DIR__ . '/Migrations');

        $middleware = $registries->get(MiddlewareRegistry::class);
        $middleware->add(SessionMiddleware::class, self::SESSION_PRIORITY);
        $middleware->add(AuthenticationMiddleware::class, self::AUTHENTICATION_PRIORITY);
        $middleware->add(CsrfMiddleware::class, self::CSRF_PRIORITY);
        $middleware->add(CompanySwitchMiddleware::class, self::COMPANY_SWITCH_PRIORITY);

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

            // Shared and mutable by design — the CompanyContext precedent:
            // the authentication middleware assigns it once per request.
            CurrentUser::class => static fn(): CurrentUser => new CurrentUser(),

            CompanySwitchMiddleware::class => static fn(
                CurrentUser $currentUser,
                CompanyContext $context,
            ): CompanySwitchMiddleware => new CompanySwitchMiddleware(
                $currentUser,
                $context,
                $config->int('database.bootstrap_company_id'),
            ),

            // Safe default the phase-5 authentication module overrides
            // (last-wins: modules contribute after every lib): no users exist,
            // so deny-by-default holds instead of resolution errors.
            // Fail closed until the phase-5 module brings real grants.
            PermissionResolver::class => static fn(): PermissionResolver => new DenyAllResolver(),

            UserProvider::class => static fn(): UserProvider => new NullUserProvider(),
        ];
    }
}
