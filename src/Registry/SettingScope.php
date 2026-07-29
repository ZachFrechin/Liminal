<?php

declare(strict_types=1);

namespace Liminal\Registry;

enum SettingScope: string
{
    case Global = 'global';
    case Company = 'company';
    case User = 'user';
}
