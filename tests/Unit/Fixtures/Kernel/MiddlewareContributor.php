<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Fixtures\Kernel;

use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\MiddlewareRegistry;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;

/**
 * Contributes the witness middleware plus a route to run it against: an
 * exception (404) would traverse the middleware without giving it a response
 * to decorate, so the end-to-end proof needs a successful request.
 */
final class MiddlewareContributor implements Contributor
{
    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(MiddlewareRegistry::class)->add(HeaderMiddleware::class, -500);
        $registries->get(RouteRegistry::class)->get('/', FixtureHandler::class);
    }
}
