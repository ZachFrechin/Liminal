<?php

declare(strict_types=1);

return [
    'name' => 'SecurityFixture',
    'debug' => true,
    'compile' => false,
    'cache_dir' => sys_get_temp_dir() . '/liminal-security-root/cache',
    'log_dir' => sys_get_temp_dir() . '/liminal-security-root/log',
    'libs' => [
        Liminal\Lib\Database\DatabaseContributor::class,
        Liminal\Lib\Security\SecurityContributor::class,
        Liminal\Lib\Module\ModuleContributor::class,
        Liminal\Lib\Rendering\RenderingContributor::class,
        Liminal\Lib\System\SystemContributor::class,
        // Last on purpose: its UserProvider definition must beat the lib's
        // NullUserProvider — the same layering a phase-5 module rides.
        Liminal\Tests\Integration\Fixtures\SecurityRoot\FixtureSecurityContributor::class,
    ],
    'modules' => [
        Liminal\Tests\Integration\Fixtures\SecurityRoot\GatedModule::class,
    ],
];
