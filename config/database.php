<?php

declare(strict_types=1);

use Liminal\Support\Env;

return [
    // A single DSN keeps local, CI and container setups interchangeable.
    // LIMINAL_TEST_DSN deliberately plays no part here: the integration test
    // suite reads it itself, and the application must never silently fall
    // back onto a test database.
    'url' => Env::nullableString('LIMINAL_DSN'),
];
