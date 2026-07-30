<?php

declare(strict_types=1);

return [
    'login_route' => 'security_fixture.login_form',
    'login_throttle' => [
        'max_failures' => 10,
        'address_max_failures' => 30,
        'window_seconds' => 900,
        'lockout_seconds' => 900,
    ],
    'session' => [
        'cookie' => 'liminal',
        'idle_ttl_seconds' => 7200,
        'absolute_ttl_seconds' => 43200,
        'secure' => false,
        'gc_percent' => 0,
    ],
];
