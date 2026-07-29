<?php

declare(strict_types=1);

use Liminal\Support\Env;

// Mirrors the real config/database.php: command tests point LIMINAL_DSN at
// the test database, unlike the unit fixture stubs that pin url to null.
return [
    'url' => Env::nullableString('LIMINAL_DSN'),
    'bootstrap_company_id' => Env::int('LIMINAL_BOOTSTRAP_COMPANY', 1),
];
