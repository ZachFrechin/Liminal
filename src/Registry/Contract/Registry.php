<?php

declare(strict_types=1);

namespace Liminal\Registry\Contract;

/**
 * A registry is the only surface through which libs and modules extend the system.
 *
 * Registries are filled during boot and frozen before the first request is handled,
 * so that no request-scoped code can mutate the system's shape at runtime.
 */
interface Registry
{
    /**
     * Prevent any further mutation. Called once by the kernel at the end of boot.
     */
    public function freeze(): void;

    public function isFrozen(): bool;
}
