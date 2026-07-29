<?php

declare(strict_types=1);

return [
    'name' => 'Fixture',
    'debug' => true,
    'compile' => false,
    'cache_dir' => sys_get_temp_dir() . '/liminal-fixture/cache',
    'log_dir' => sys_get_temp_dir() . '/liminal-fixture/log',
    'libs' => [
        'Liminal\\Tests\\Unit\\Fixtures\\Kernel\\DoesNotExist',
    ],
];
