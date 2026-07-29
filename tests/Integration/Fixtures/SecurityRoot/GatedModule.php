<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Registry\Contract\Module;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;

/**
 * A declared module with one public route, so the gate can be observed
 * without authentication getting in the way first.
 */
final class GatedModule implements Module
{
    public function name(): string
    {
        return 'gated';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function migrationNamespace(): ?string
    {
        return null;
    }

    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(RouteRegistry::class)
            ->get('/gated', GatedHandler::class, 'gated.index', public: true);
    }
}
