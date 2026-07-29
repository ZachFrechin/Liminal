<?php

declare(strict_types=1);

namespace Liminal\Registry;

/**
 * Where a setting value applies: installation-wide, per company, or per user.
 * Read-time precedence is user over company over global.
 */
enum SettingScope: string
{
    case Global = 'global';
    case Company = 'company';
    case User = 'user';
}
