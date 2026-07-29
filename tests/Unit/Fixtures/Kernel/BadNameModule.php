<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Fixtures\Kernel;

use Liminal\Registry\Contract\Module;
use Liminal\Registry\RegistryCollection;

/**
 * Declares a name that can never be stored in core_module — boot must refuse
 * the manifest before anything else runs.
 */
final class BadNameModule implements Module
{
    public function name(): string
    {
        return 'Not A Slug!';
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
