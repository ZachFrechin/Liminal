<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication;

use Liminal\Config\Configuration;
use Liminal\Lib\Database\DeferredConnection;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Security\Contract\AuthEventLog;
use Liminal\Lib\Security\Contract\LoginThrottle;
use Liminal\Lib\Security\Contract\PermissionResolver;
use Liminal\Lib\Security\Contract\TokenProvider;
use Liminal\Lib\Security\Contract\UserProvider;
use Liminal\Module\Authentication\Administration\TokenAdministration;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Liminal\Module\Authentication\Console\RoleGrantCommand;
use Liminal\Module\Authentication\Console\TokenCreateCommand;
use Liminal\Module\Authentication\Console\TokenListCommand;
use Liminal\Module\Authentication\Console\TokenRevokeCommand;
use Liminal\Module\Authentication\Console\UserCreateCommand;
use Liminal\Module\Authentication\Http\AccountHandler;
use Liminal\Module\Authentication\Http\LoginPageHandler;
use Liminal\Module\Authentication\Http\LoginSubmitHandler;
use Liminal\Module\Authentication\Http\LogoutHandler;
use Liminal\Module\Authentication\Http\RoleCreatePageHandler;
use Liminal\Module\Authentication\Http\RoleCreateSubmitHandler;
use Liminal\Module\Authentication\Http\RoleDeleteHandler;
use Liminal\Module\Authentication\Http\RoleDetailHandler;
use Liminal\Module\Authentication\Http\RoleListHandler;
use Liminal\Module\Authentication\Http\RoleUpdateHandler;
use Liminal\Module\Authentication\Http\SwitchCompanyHandler;
use Liminal\Module\Authentication\Http\UserCreatePageHandler;
use Liminal\Module\Authentication\Http\UserCreateSubmitHandler;
use Liminal\Module\Authentication\Http\UserDeleteHandler;
use Liminal\Module\Authentication\Http\UserDetailHandler;
use Liminal\Module\Authentication\Http\UserGrantHandler;
use Liminal\Module\Authentication\Http\UserListHandler;
use Liminal\Module\Authentication\Http\UserPasswordResetHandler;
use Liminal\Module\Authentication\Http\UserRevokeHandler;
use Liminal\Module\Authentication\Http\UserUpdateHandler;
use Liminal\Module\Authentication\Security\DbalAuthEventLog;
use Liminal\Module\Authentication\Security\DbalLoginThrottle;
use Liminal\Module\Authentication\Security\DbalPermissionResolver;
use Liminal\Module\Authentication\Security\DbalTokenProvider;
use Liminal\Module\Authentication\Security\DbalUserProvider;
use Liminal\Registry\CommandRegistry;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\Contract\Module;
use Liminal\Registry\EntityRegistry;
use Liminal\Registry\MenuItem;
use Liminal\Registry\MenuRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\Permission;
use Liminal\Registry\PermissionRegistry;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;
use Liminal\Registry\TemplateRegistry;
use Liminal\Registry\TranslationRegistry;
use Liminal\Registry\TriggerRegistry;
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
    /** The module's identity: its name, its route prefix and its template namespace. */
    public const string NAME = 'authentication';

    public const MIGRATION_NAMESPACE = 'Liminal\Module\Authentication\Migrations';

    public const ENTITY_NAMESPACE = 'Liminal\Module\Authentication\Entity';

    public function name(): string
    {
        return self::NAME;
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

        // Registered so schema tooling and future ORM consumers see the shape.
        // Nothing on the request path touches them: security reads are DBAL by
        // rule, and the administration writes stayed DBAL like every other
        // production write in the tree.
        $registries->get(EntityRegistry::class)
            ->add(self::ENTITY_NAMESPACE, __DIR__ . '/Entity');

        $registries->get(TemplateRegistry::class)
            ->add(self::NAME, __DIR__ . '/templates');

        $registries->get(TranslationRegistry::class)
            ->add('en', __DIR__ . '/lang/en.php');

        // Public: sign-in and sign-out must work before — and regardless of
        // whether — this module is enabled for anyone's company.
        $routes = $registries->get(RouteRegistry::class);
        $routes->get('/login', LoginPageHandler::class, 'authentication.login', public: true);
        $routes->post('/login', LoginSubmitHandler::class, 'authentication.login_submit', public: true);
        $routes->post('/logout', LogoutHandler::class, 'authentication.logout', public: true);
        $routes->get('/account', AccountHandler::class, 'authentication.account');
        $routes->post('/switch-company', SwitchCompanyHandler::class, 'authentication.switch');

        // User administration. Statics before dynamics, and {id:\d+} is
        // load-bearing: a bare {id} would also match "create", and FastRoute
        // then refuses the static route or shadows it depending on order.
        $routes->get('/users', UserListHandler::class, 'authentication.users');
        $routes->get('/users/create', UserCreatePageHandler::class, 'authentication.user_create');
        $routes->post('/users/create', UserCreateSubmitHandler::class, 'authentication.user_create_submit');
        $routes->get('/users/{id:\d+}', UserDetailHandler::class, 'authentication.user');
        $routes->post('/users/{id:\d+}', UserUpdateHandler::class, 'authentication.user_update');
        $routes->post('/users/{id:\d+}/delete', UserDeleteHandler::class, 'authentication.user_delete');
        $routes->post('/users/{id:\d+}/grants', UserGrantHandler::class, 'authentication.user_grant');
        $routes->post('/users/{id:\d+}/grants/revoke', UserRevokeHandler::class, 'authentication.user_revoke');
        $routes->post('/users/{id:\d+}/password', UserPasswordResetHandler::class, 'authentication.user_password');

        // Role administration — its own permission: editing what a role MEANS
        // is a different blast radius than deciding who holds it.
        $routes->get('/roles', RoleListHandler::class, 'authentication.roles');
        $routes->get('/roles/create', RoleCreatePageHandler::class, 'authentication.role_create');
        $routes->post('/roles/create', RoleCreateSubmitHandler::class, 'authentication.role_create_submit');
        $routes->get('/roles/{id:\d+}', RoleDetailHandler::class, 'authentication.role');
        $routes->post('/roles/{id:\d+}', RoleUpdateHandler::class, 'authentication.role_update');
        $routes->post('/roles/{id:\d+}/delete', RoleDeleteHandler::class, 'authentication.role_delete');

        $permissions = $registries->get(PermissionRegistry::class);
        $permissions->add(new Permission(UserListHandler::PERMISSION, 'authentication.permission.user.manage', self::NAME));
        $permissions->add(new Permission(RoleListHandler::PERMISSION, 'authentication.permission.role.manage', self::NAME));

        // What this module announces to whoever listens — the audit hears all
        // of it through the security lib's catch-all.
        $triggers = $registries->get(TriggerRegistry::class);

        foreach ([
            'USER_CREATED', 'USER_UPDATED', 'USER_DELETED', 'USER_PASSWORD_RESET',
            'GRANT_ADDED', 'GRANT_REVOKED',
            'ROLE_CREATED', 'ROLE_UPDATED', 'ROLE_DELETED',
            'TOKEN_CREATED', 'TOKEN_REVOKED',
        ] as $name) {
            $triggers->declare($name);
        }

        // Bootstrap commands. Their constructors inject only deferred-connection
        // services: the console resolves every registered command eagerly.
        $commands = $registries->get(CommandRegistry::class);
        $commands->add(UserCreateCommand::class);
        $commands->add(RoleGrantCommand::class);
        $commands->add(TokenCreateCommand::class);
        $commands->add(TokenListCommand::class);
        $commands->add(TokenRevokeCommand::class);

        $menu = $registries->get(MenuRegistry::class);
        $menu->add(new MenuItem('authentication.menu.account', 'authentication.account', priority: 900, icon: 'user-round'));
        $menu->add(new MenuItem(
            'authentication.menu.users',
            'authentication.users',
            UserListHandler::PERMISSION,
            priority: 910,
            icon: 'users',
        ));
        $menu->add(new MenuItem(
            'authentication.menu.roles',
            'authentication.roles',
            RoleListHandler::PERMISSION,
            priority: 915,
            icon: 'shield-check',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function definitions(Configuration $config): array
    {
        return [
            UserProvider::class => static fn(ContainerInterface $container): UserProvider
                => new DbalUserProvider(DeferredConnection::resolver($container)),

            // The bearer credential storage behind the security lib's
            // contract — the same last-wins override as UserProvider.
            TokenProvider::class => static fn(ContainerInterface $container): TokenProvider
                => new DbalTokenProvider(DeferredConnection::resolver($container)),

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

            // Deferred too: the bootstrap commands inject it, and the console
            // resolves every command eagerly.
            UserAdministration::class => static fn(ContainerInterface $container): UserAdministration
                => new UserAdministration(DeferredConnection::resolver($container)),

            TokenAdministration::class => static fn(ContainerInterface $container): TokenAdministration
                => new TokenAdministration(DeferredConnection::resolver($container)),
        ];
    }
}
