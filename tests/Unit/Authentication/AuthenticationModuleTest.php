<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Authentication;

use Liminal\Config\ConfigurationLoader;
use Liminal\Module\Authentication\AuthenticationModule;
use Liminal\Registry\CommandRegistry;
use Liminal\Registry\EntityRegistry;
use Liminal\Registry\MenuRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\PermissionRegistry;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;
use Liminal\Registry\TemplateRegistry;
use Liminal\Registry\TranslationRegistry;
use Liminal\Registry\TriggerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthenticationModule::class)]
final class AuthenticationModuleTest extends TestCase
{
    public function testTheManifestNamesTheMigrationNamespaceItRegisters(): void
    {
        $module = new AuthenticationModule();
        $migrations = new MigrationRegistry();

        // The boot asserts these agree; prove they do without booting.
        $module->contribute($this->registriesWith($migrations));

        self::assertArrayHasKey($module->migrationNamespace(), $migrations->all());
    }

    public function testTheModuleNameIsTheSlugTheKernelAccepts(): void
    {
        self::assertSame('authentication', new AuthenticationModule()->name());
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', new AuthenticationModule()->name());
    }

    /**
     * The boot enforces this, but a unit test says which route broke without
     * standing up a kernel first.
     */
    public function testEveryContributedRouteCarriesTheModulePrefix(): void
    {
        $module = new AuthenticationModule();
        $routes = new RouteRegistry();

        $module->contribute($this->registriesWith(new MigrationRegistry(), $routes));

        self::assertNotSame([], $routes->all());

        foreach ($routes->all() as $route) {
            self::assertIsString($route->name);
            self::assertStringStartsWith($module->name() . '.', $route->name);
        }
    }

    /**
     * The security lib redirects a browser 401 to a configured route name. If
     * the two ever drift, every login redirect silently falls back to a
     * rendered 401 page — so pin them together.
     */
    public function testTheConfiguredLoginRouteBelongsToThisModule(): void
    {
        $config = new ConfigurationLoader(dirname(__DIR__, 3) . '/config')->load('app', 'database', 'security');

        self::assertStringStartsWith(
            new AuthenticationModule()->name() . '.',
            $config->string('security.login_route'),
        );
    }

    private function registriesWith(MigrationRegistry $migrations, ?RouteRegistry $routes = null): RegistryCollection
    {
        return new RegistryCollection([
            $migrations,
            $routes ?? new RouteRegistry(),
            new CommandRegistry(),
            new EntityRegistry(),
            new TemplateRegistry(),
            new TranslationRegistry(),
            new PermissionRegistry(),
            new MenuRegistry(),
            new TriggerRegistry(),
        ]);
    }
}
