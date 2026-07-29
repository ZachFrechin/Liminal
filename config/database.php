<?php

declare(strict_types=1);

use Liminal\Support\Env;

return [
    // A single DSN keeps local, CI and container setups interchangeable.
    'url' => Env::nullableString('LIMINAL_DSN') ?? Env::nullableString('LIMINAL_TEST_DSN'),

    // No global table prefix: the per-module prefix (core_, invoicing_, ...) is
    // already the boundary the registries can verify.
    'prefix' => '',
];
