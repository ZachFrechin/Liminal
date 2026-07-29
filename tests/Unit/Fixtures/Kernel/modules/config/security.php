<?php

declare(strict_types=1);

// Fixture stub. gc_percent 0 keeps tests deterministic: no session sweep
// fires behind the scenario under test.
return [
    'session' => [
        'cookie' => 'liminal',
        'idle_ttl_seconds' => 7200,
        'absolute_ttl_seconds' => 43200,
        'secure' => false,
        'gc_percent' => 0,
    ],
];
