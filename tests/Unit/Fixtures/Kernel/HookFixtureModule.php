<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Fixtures\Kernel;

use Liminal\Registry\Contract\Module;
use Liminal\Registry\HookRegistry;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;

/**
 * The hooks' end-to-end fixture: declares a hook, subscribes two prioritized
 * listeners, and exposes a route whose handler filters a value through them.
 *
 * The first PRODUCTION hook arrives with the documents phase —
 * invoice.total.compute, the name the phase-0 table promised — this fixture
 * exists so the primitive is proven through a real HTTP round trip before
 * that day.
 */
final class HookFixtureModule implements Module
{
    public function name(): string
    {
        return 'hook_fixture';
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
        $hooks = $registries->get(HookRegistry::class);
        $hooks->declare('hook_fixture.value.compute');
        // Registered out of order on purpose: priorities decide, not order.
        $hooks->listen('hook_fixture.value.compute', HookFixtureDoubleListener::class, priority: 200);
        $hooks->listen('hook_fixture.value.compute', HookFixtureAddTenListener::class, priority: 100);

        $registries->get(RouteRegistry::class)
            ->get('/compute', HookFixtureComputeHandler::class, 'hook_fixture.compute', public: true);
    }
}
