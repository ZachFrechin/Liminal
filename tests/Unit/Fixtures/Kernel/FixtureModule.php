<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Fixtures\Kernel;

use Liminal\Config\Configuration;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\Contract\Module;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;

/**
 * A well-behaved module manifest: identity, a route under its name prefix, a
 * registered migration namespace matching the manifest, and a definition that
 * must override the lib-provided one (declaration layering).
 */
final class FixtureModule implements Module, DefinitionProvider
{
    public const MIGRATION_NAMESPACE = 'Liminal\Tests\Unit\Fixtures\Kernel\FixtureMigrations';

    public function name(): string
    {
        return 'fixture_module';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function migrationNamespace(): string
    {
        return self::MIGRATION_NAMESPACE;
    }

    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(RouteRegistry::class)
            ->get('/fixture', FixtureHandler::class, 'fixture_module.ping');

        // Never scanned without a database; registered so the manifest and
        // the registry agree, which the boot asserts.
        $registries->get(MigrationRegistry::class)
            ->add(self::MIGRATION_NAMESPACE, __DIR__ . '/FixtureMigrations');
    }

    public function definitions(Configuration $config): array
    {
        return [
            'fixture.greeting' => static fn(): string => 'hello from module',
        ];
    }
}
