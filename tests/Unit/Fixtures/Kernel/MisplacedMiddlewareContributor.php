<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Fixtures\Kernel;

use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\MiddlewareRegistry;
use Liminal\Registry\RegistryCollection;

/**
 * Registers a middleware behind the dispatcher, where it could never run —
 * boot must refuse it by name.
 */
final class MisplacedMiddlewareContributor implements Contributor
{
    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(MiddlewareRegistry::class)->add(HeaderMiddleware::class, 2000);
    }
}
