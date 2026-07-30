<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty;

use Liminal\Config\Configuration;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\Contract\Module;
use Liminal\Registry\EntityRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\RegistryCollection;

/**
 * The thirdparty module: the parties this installation does business with —
 * customers, suppliers, and the prospects that are not yet either.
 *
 * The first business vertical, and therefore the first PRODUCTION consumer of
 * the CompanyScoped machinery: its table is fenced per company by the phase-1
 * filter, stamped by prePersist, guarded by postLoad and the write-once flush
 * gate. Business CRUD goes through the ORM — those protections live nowhere
 * else.
 */
final class ThirdpartyModule implements Module, DefinitionProvider
{
    /** The module's identity: its name, its route prefix and its template namespace. */
    public const string NAME = 'thirdparty';

    public const string MIGRATION_NAMESPACE = 'Liminal\Module\Thirdparty\Migrations';

    public const string ENTITY_NAMESPACE = 'Liminal\Module\Thirdparty\Entity';

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

        // On the request path this time: business reads and writes go through
        // the ORM, because the company fence only exists there.
        $registries->get(EntityRegistry::class)
            ->add(self::ENTITY_NAMESPACE, __DIR__ . '/Entity');
    }

    /**
     * @return array<string, mixed>
     */
    public function definitions(Configuration $config): array
    {
        // The repository's constructor takes only container services
        // (EntityManagerInterface, CompanyContext): autowiring covers it.
        return [];
    }
}
