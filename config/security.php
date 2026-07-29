<?php

declare(strict_types=1);

use Liminal\Support\Env;

return [
    'session' => [
        // Set '__Host-liminal' in production: the cookie prefix pins Path=/,
        // Secure and no-Domain at the browser level for free.
        'cookie' => Env::string('LIMINAL_SESSION_COOKIE', 'liminal'),

        // Idle timeout: a session dies after this much inactivity.
        'idle_ttl_seconds' => Env::int('LIMINAL_SESSION_IDLE_TTL', 7200),

        // Absolute cap counted from authentication: even an active session
        // ends. 1800/28800 for strict OWASP alignment; 2h/12h is the
        // pragmatic ERP default.
        'absolute_ttl_seconds' => Env::int('LIMINAL_SESSION_ABSOLUTE_TTL', 43200),

        // Secure-by-default; dev over plain HTTP exports 0. Never derived
        // from the request scheme: there is no trusted-proxy layer to make
        // that honest.
        'secure' => Env::bool('LIMINAL_COOKIE_SECURE', true),

        // Percent chance per request start that expired sessions are swept;
        // 0 disables the lottery (tests, or when cron owns session:gc).
        'gc_percent' => Env::int('LIMINAL_SESSION_GC_PERCENT', 1),
    ],
];
