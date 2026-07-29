<?php

declare(strict_types=1);

namespace Liminal\Registry;

final readonly class SettingDefinition
{
    public function __construct(
        public string $key,
        public SettingScope $scope,
        public string|int|float|bool|null $default = null,
        public string $label = '',
    ) {
    }
}
