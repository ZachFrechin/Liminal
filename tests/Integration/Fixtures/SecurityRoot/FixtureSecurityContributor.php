<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Config\Configuration;
use Liminal\Lib\Security\Contract\UserProvider;
use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\EntityRegistry;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;
use Liminal\Registry\TemplateRegistry;

/**
 * The security lib's test double of a phase-5 authentication module: it
 * overrides the UserProvider binding through the SAME last-wins definition
 * layering the real module will use, and contributes the fixture endpoints
 * the HTTP flows drive.
 */
final class FixtureSecurityContributor implements Contributor, DefinitionProvider
{
    public function contribute(RegistryCollection $registries): void
    {
        $routes = $registries->get(RouteRegistry::class);
        $routes->get('/token', TokenHandler::class, 'security_fixture.token', public: true);
        $routes->post('/login', LoginHandler::class, 'security_fixture.login', public: true);
        $routes->get('/me', MeHandler::class, 'security_fixture.me');
        $routes->post('/logout', LogoutHandler::class, 'security_fixture.logout');
        $routes->get('/widgets', WidgetsHandler::class, 'security_fixture.widgets');
        $routes->get('/page', PageHandler::class, 'security_fixture.page');
        $routes->get('/form', FormHandler::class, 'security_fixture.form', public: true);
        $routes->get('/flash', FlashHandler::class, 'security_fixture.flash', public: true);

        $registries->get(EntityRegistry::class)
            ->add('Liminal\Tests\Integration\Fixtures\Entity', dirname(__DIR__) . '/Entity');

        $registries->get(TemplateRegistry::class)
            ->add('security_fixture', __DIR__ . '/templates');
    }

    /**
     * @return array<string, mixed>
     */
    public function definitions(Configuration $config): array
    {
        return [
            UserProvider::class => static fn(): UserProvider => new FixtureUserProvider(),
        ];
    }
}
