<?php

declare(strict_types=1);

return [
    'name' => 'ModuleFixture',
    'debug' => true,
    'compile' => false,
    'cache_dir' => sys_get_temp_dir() . '/liminal-module-root/cache',
    'log_dir' => sys_get_temp_dir() . '/liminal-module-root/log',
    'libs' => [
        Liminal\Lib\Database\DatabaseContributor::class,
        Liminal\Lib\Module\ModuleContributor::class,
        Liminal\Lib\System\SystemContributor::class,
    ],
    'modules' => [
        Liminal\Tests\Integration\Fixtures\ModuleFixture\IntegrationModule::class,
    ],
];
