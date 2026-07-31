<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Sequence;

use Doctrine\DBAL\Connection;
use Liminal\Lib\Database\Exception\DatabaseException;

/**
 * The gap-free per-company-per-year counter claim — the concurrency-bearing
 * code every document sequence shares, in ONE exemplar. The atomic upsert
 * X-locks the (company_id, year) row until the caller's OUTER commit, so
 * concurrent claims serialise and any rollback releases the number with
 * everything else. Callers run this inside their own wrapInTransaction on
 * the SAME connection — the lock semantics belong to the transaction, not
 * to this method.
 *
 * The table name comes from module CONSTANTS ('invoice_sequence',
 * 'order_sequence'), never from user input — and because interpolating an
 * identifier into SQL has no precedent in this tree, the grammar guard below
 * is a runtime throw, not a docblock promise.
 */
final class YearlySequence
{
    private function __construct() {}

    /**
     * @param string $table a module-owned sequence table constant, columns
     *                      (company_id, year, counter) with PK (company_id, year)
     *
     * @throws DatabaseException when the table name is not a bare lowercase identifier
     */
    public static function claim(Connection $connection, string $table, int $companyId, int $year): int
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
            throw DatabaseException::unsafeSequenceTable($table);
        }

        // The throttle precedent: an atomic increment, never a
        // read-modify-write. MariaDB's VALUES() on purpose.
        $connection->executeStatement(
            'INSERT INTO ' . $table . ' (company_id, year, counter) VALUES (?, ?, 1)'
            . ' ON DUPLICATE KEY UPDATE counter = counter + 1',
            [$companyId, $year],
        );

        // Reads its own locked write; race-safe under the held lock.
        $raw = $connection->fetchOne(
            'SELECT counter FROM ' . $table . ' WHERE company_id = ? AND year = ?',
            [$companyId, $year],
        );

        return is_numeric($raw) ? (int) $raw : 1;
    }
}
