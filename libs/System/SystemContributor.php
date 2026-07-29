<?php

declare(strict_types=1);

namespace Liminal\Lib\System;

use Liminal\Lib\System\Console\DoctorCommand;
use Liminal\Lib\System\Console\InstallCommand;
use Liminal\Lib\System\Http\HealthController;
use Liminal\Registry\CommandRegistry;
use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;
use Liminal\Registry\SettingDefinition;
use Liminal\Registry\SettingScope;
use Liminal\Registry\SettingsRegistry;

/**
 * Diagnostics surface of the core.
 *
 * It exists so the kernel itself never declares a route: even "/" arrives through
 * the same registry path a third-party module would use.
 */
final class SystemContributor implements Contributor
{
    public function contribute(RegistryCollection $registries): void
    {
        // Public on purpose: liveness must answer before anyone can log in.
        $registries->get(RouteRegistry::class)
            ->get('/', HealthController::class, 'system.health', public: true);

        $commands = $registries->get(CommandRegistry::class);
        $commands->add(DoctorCommand::class);
        $commands->add(InstallCommand::class);

        $registries->get(SettingsRegistry::class)
            ->add(new SettingDefinition('system.maintenance', SettingScope::Global, false, 'Maintenance mode'));
    }
}
