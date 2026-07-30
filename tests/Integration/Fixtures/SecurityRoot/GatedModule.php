<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Registry\Contract\Module;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;

/**
 * A declared module with one protected route (what the gate acts on) and one
 * public route (what it must leave alone).
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
        $routes = $registries->get(RouteRegistry::class);
        // One of each: the gate only ever looks at the protected one, and the
        // public one proves it stays out of the way.
        $routes->get('/gated', GatedHandler::class, 'gated.index');
        $routes->get('/gated-public', GatedHandler::class, 'gated.public', public: true);
    }
}
