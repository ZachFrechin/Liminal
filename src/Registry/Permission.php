<?php

declare(strict_types=1);

namespace Liminal\Registry;

final readonly class Permission
{
    public function __construct(
        public string $code,
        public string $label,
        public string $group = 'general',
    ) {
    }
}
