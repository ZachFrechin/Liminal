<?php

declare(strict_types=1);

use Liminal\Support\Env;

$root = dirname(__DIR__);

return [
    'name' => 'Liminal',
    'debug' => Env::bool('LIMINAL_DEBUG', true),

    // The locale every render resolves translations against. Deliberately
    // configuration, not a database setting: anonymous pages must render on a
    // checkout with no DSN at all.
    'locale' => Env::string('LIMINAL_LOCALE', 'en'),

    // Compiling the container is what makes module activation a cache-rebuild step
    // rather than a restart. Off in dev so contributions are picked up immediately.
    'compile' => Env::bool('LIMINAL_COMPILE', false),

    'cache_dir' => $root . '/var/cache',
    'log_dir' => $root . '/var/log',

    // Contribution order is significant: libs first, in this order, then
    // modules. A module's container DEFINITIONS override a lib's (last wins);
    // registry keys still refuse duplicates everywhere — overriding a lib's
    // route or permission will be an explicit API, not a silent collision.
    'libs' => [
        Liminal\Lib\Database\DatabaseContributor::class,
        Liminal\Lib\Hook\HookContributor::class,
        Liminal\Lib\Security\SecurityContributor::class,
        Liminal\Lib\Module\ModuleContributor::class,
        Liminal\Lib\Rendering\RenderingContributor::class,
        Liminal\Lib\System\SystemContributor::class,
    ],

    // Business modules, contributed after every lib. Declared here means
    // "part of this installation": their shape (routes, entities, migrations)
    // always boots; whether they are ENABLED is per company, in the database.
    'modules' => [
        Liminal\Module\Authentication\AuthenticationModule::class,
        Liminal\Module\Companies\CompaniesModule::class,
        Liminal\Module\Thirdparty\ThirdpartyModule::class,
        // After thirdparty on purpose: invoice depends on it, one direction.
        Liminal\Module\Invoice\InvoiceModule::class,
        // After both of its dependencies: an order names a thirdparty, and
        // converting one creates an invoice.
        Liminal\Module\Order\OrderModule::class,
    ],
];
