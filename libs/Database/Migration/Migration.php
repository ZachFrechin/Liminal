<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Migration;

use Doctrine\Migrations\AbstractMigration;

/**
 * Base class for every Liminal migration.
 *
 * Non-transactional on purpose, and not just cosmetically: MariaDB commits
 * DDL implicitly, so the per-migration transaction AbstractMigration opens by
 * default is a lie there — and the phantom commit desyncs DBAL's savepoint
 * bookkeeping, breaking every later transactional() call on the same
 * connection. A migration that needs atomic DML manages its own transaction
 * explicitly around those statements.
 */
abstract class Migration extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }
}
