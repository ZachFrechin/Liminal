<?php

declare(strict_types=1);

namespace Liminal\Lib\Database;

use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\EntityRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\RegistryCollection;

/**
 * lib/database contributes exactly like any module would: it registers its own
 * entity and migration namespaces and nothing more.
 */
final class DatabaseContributor implements Contributor
{
    public const ENTITY_NAMESPACE = 'Liminal\Lib\Database\Entity';

    public const MIGRATION_NAMESPACE = 'Liminal\Lib\Database\Migrations';

    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(EntityRegistry::class)
            ->add(self::ENTITY_NAMESPACE, __DIR__ . '/Entity');

        $registries->get(MigrationRegistry::class)
            ->add(self::MIGRATION_NAMESPACE, __DIR__ . '/Migrations');
    }
}
