<?php

declare(strict_types=1);

namespace Liminal\Registry;

/**
 * Declares one setting a module may read: key, scope, default and label. The
 * definition is the contract; the values themselves live in core_setting.
 */
final readonly class SettingDefinition
{
    public function __construct(
        public string $key,
        public SettingScope $scope,
        public string|int|float|bool|null $default = null,
        public string $label = '',
    ) {}
}
