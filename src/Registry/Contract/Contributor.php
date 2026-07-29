<?php

declare(strict_types=1);

namespace Liminal\Registry\Contract;

use Liminal\Registry\RegistryCollection;

/**
 * Implemented identically by libs and modules: it is the single coupling point
 * between them and the kernel. Nothing else may reach into the core.
 */
interface Contributor
{
    public function contribute(RegistryCollection $registries): void;
}
