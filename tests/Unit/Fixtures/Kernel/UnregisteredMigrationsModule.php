<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Fixtures\Kernel;

use Liminal\Registry\Contract\Module;
use Liminal\Registry\RegistryCollection;

/**
 * The manifest/registry drift case: names a migration namespace its
 * contribute() never registers, which would make module:install silently plan
 * nothing — boot must refuse it.
 */
final class UnregisteredMigrationsModule implements Module
{
    public function name(): string
    {
        return 'drifting_module';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function migrationNamespace(): string
    {
        return 'Liminal\Tests\Unit\Fixtures\Kernel\NeverRegistered';
    }

    public function contribute(RegistryCollection $registries): void {}
}
