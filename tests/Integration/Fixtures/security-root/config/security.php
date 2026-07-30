<?php

declare(strict_types=1);

return [
    'login_route' => 'security_fixture.login_form',
    'session' => [
        'cookie' => 'liminal',
        'idle_ttl_seconds' => 7200,
        'absolute_ttl_seconds' => 43200,
        'secure' => false,
        'gc_percent' => 0,
    ],
];
