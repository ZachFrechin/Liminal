<?php

declare(strict_types=1);

namespace Liminal\Registry\Contract;

use Liminal\Registry\RegistryCollection;

/**
 * Implemented identically by libs and modules: it is the single coupling point
 * between them and the kernel. Nothing else may reach into the core.
 *
 * Contributors are manifests. The kernel instantiates them with plain `new`
 * before the container exists, so they must not require constructor arguments;
 * anything they need at contribution time arrives as a parameter, and services
 * they want to expose belong in DefinitionProvider::definitions() closures.
 */
interface Contributor
{
    public function contribute(RegistryCollection $registries): void;
}
