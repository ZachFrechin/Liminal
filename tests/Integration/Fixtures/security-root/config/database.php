<?php

declare(strict_types=1);

use Liminal\Support\Env;

return [
    'url' => Env::nullableString('LIMINAL_DSN'),
    'bootstrap_company_id' => Env::int('LIMINAL_BOOTSTRAP_COMPANY', 1),
];
