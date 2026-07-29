<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\ModuleFixture;

use Liminal\Registry\Contract\Module;
use Liminal\Registry\RegistryCollection;

/**
 * A module with no migrations at all: installing it must not even create the
 * migration metadata table.
 */
final class BareModule implements Module
{
    public function name(): string
    {
        return 'bare';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function migrationNamespace(): ?string
    {
        return null;
    }

    public function contribute(RegistryCollection $registries): void {}
}
