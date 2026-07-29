<?php

declare(strict_types=1);

namespace Liminal\Registry;

/**
 * One grantable permission a module declares, keyed by its unique code; group
 * only clusters related permissions on future admin screens.
 */
final readonly class Permission
{
    public function __construct(
        public string $code,
        public string $label,
        public string $group = 'general',
    ) {}
}
