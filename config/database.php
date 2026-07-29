<?php

declare(strict_types=1);

use Liminal\Support\Env;

return [
    // A single DSN keeps local, CI and container setups interchangeable.
    // LIMINAL_TEST_DSN deliberately plays no part here: the integration test
    // suite reads it itself, and the application must never silently fall
    // back onto a test database.
    'url' => Env::nullableString('LIMINAL_DSN'),

    // The company the process starts scoped to, before any authentication
    // exists. Phase 3 switches per request via CompanyContext::switchTo();
    // until then this is the installer-seeded first company.
    'bootstrap_company_id' => Env::int('LIMINAL_BOOTSTRAP_COMPANY', 1),
];
