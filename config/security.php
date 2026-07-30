<?php

declare(strict_types=1);

use Liminal\Support\Env;

return [
    // The route a browser is redirected to on a 401. A route name is code
    // wiring, not deployment config, so no env var.
    'login_route' => 'authentication.login',

    // Login rate limiting. Deliberately configuration, not database settings:
    // these protect the pre-company login path, must work before any row
    // exists, and must not be per-company state an admin can zero out.
    'login_throttle' => [
        'max_failures' => Env::int('LIMINAL_LOGIN_MAX_FAILURES', 10),
        'address_max_failures' => Env::int('LIMINAL_LOGIN_ADDRESS_MAX_FAILURES', 30),
        'window_seconds' => Env::int('LIMINAL_LOGIN_WINDOW', 900),
        'lockout_seconds' => Env::int('LIMINAL_LOGIN_LOCKOUT', 900),
    ],

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
