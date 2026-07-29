<?php

declare(strict_types=1);

namespace Liminal\Lib\Module;

use Liminal\Config\Configuration;
use Liminal\Lib\Database\DeferredConnection;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Module\Console\DisableCommand;
use Liminal\Lib\Module\Console\EnableCommand;
use Liminal\Lib\Module\Console\InstallCommand;
use Liminal\Lib\Module\Console\ListCommand;
use Liminal\Registry\CommandRegistry;
use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\ModuleRegistry;
use Liminal\Registry\RegistryCollection;
use Psr\Container\ContainerInterface;

/**
 * lib/module: the module lifecycle as a technical capability. Consumes the
 * Database lib's migration and connection services — a sanctioned lib-to-lib
 * edge, like System's.
 */
final class ModuleContributor implements Contributor, DefinitionProvider
{
    public function contribute(RegistryCollection $registries): void
    {
        $commands = $registries->get(CommandRegistry::class);
        $commands->add(InstallCommand::class);
        $commands->add(EnableCommand::class);
        $commands->add(DisableCommand::class);
        $commands->add(ListCommand::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function definitions(Configuration $config): array
    {
        return [
            // Deferred for the console-eager-resolution reason in CONVENTIONS:
            // every module command injects this manager.
            ModuleManager::class => static fn(
                ModuleRegistry $modules,
                MigrationRunner $migrations,
                ContainerInterface $container,
            ): ModuleManager
                => new ModuleManager($modules, $migrations, DeferredConnection::resolver($container)),
        ];
    }
}
