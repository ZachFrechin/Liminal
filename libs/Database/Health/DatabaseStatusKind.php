<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Health;

/**
 * The three states a doctor run can find the database in. NotConfigured is a
 * reported state, never an error: a fresh checkout without a DSN must still
 * get a clean doctor pass.
 */
enum DatabaseStatusKind
{
    case NotConfigured;
    case Unreachable;
    case Ok;
}
