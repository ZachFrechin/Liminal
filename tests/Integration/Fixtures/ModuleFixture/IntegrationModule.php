<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\ModuleFixture;

use Liminal\Registry\Contract\Module;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\RegistryCollection;

/**
 * The lifecycle test subject: one real migration namespace of its own. The
 * optional constructor argument keeps it boot-instantiable while letting
 * tests build a "same module, newer manifest" instance for the upgrade path.
 */
final class IntegrationModule implements Module
{
    public const MIGRATION_NAMESPACE = 'Liminal\Tests\Integration\Fixtures\ModuleFixture\Migrations';

    public function __construct(private readonly string $version = '1.0.0') {}

    public function name(): string
    {
        return 'fixture';
    }

    public function version(): string
    {
        return $this->version;
    }

    public function migrationNamespace(): string
    {
        return self::MIGRATION_NAMESPACE;
    }

    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(MigrationRegistry::class)
            ->add(self::MIGRATION_NAMESPACE, __DIR__ . '/Migrations');
    }
}
