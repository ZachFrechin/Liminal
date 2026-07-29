<?php

declare(strict_types=1);

use Liminal\Support\Env;

$root = dirname(__DIR__);

return [
    'name' => 'Liminal',
    'env' => Env::string('LIMINAL_ENV', 'dev'),
    'debug' => Env::bool('LIMINAL_DEBUG', true),

    // Compiling the container is what makes module activation a cache-rebuild step
    // rather than a restart. Off in dev so contributions are picked up immediately.
    'compile' => Env::bool('LIMINAL_COMPILE', false),

    'root_dir' => $root,
    'cache_dir' => $root . '/var/cache',
    'log_dir' => $root . '/var/log',

    // Contribution order is significant: libs first, in this order, then modules.
    // A module may override a lib's contribution; never the reverse.
    'libs' => [
        Liminal\Lib\Database\DatabaseContributor::class,
        Liminal\Lib\System\SystemContributor::class,
    ],
];
