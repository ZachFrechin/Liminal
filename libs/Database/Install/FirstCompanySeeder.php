<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Install;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Seeds the very first company — and only ever the first: any existing row
 * means an installed instance, and the seeder backs off. A fresh
 * AUTO_INCREMENT hands the row id 1, matching the bootstrap company the
 * process scopes to by default.
 */
final readonly class FirstCompanySeeder
{
    /**
     * @param Closure(): Connection $connection deferred so a missing DSN fails at run time, not boot
     */
    public function __construct(private Closure $connection) {}

    public function seed(string $code, string $name): SeedResult
    {
        $connection = ($this->connection)();

        $count = $connection->fetchOne('SELECT COUNT(*) FROM core_company');
        $existing = is_numeric($count) ? (int) $count : 0;

        if ($existing > 0) {
            return SeedResult::skipped($existing);
        }

        $now = new DateTimeImmutable()->format('Y-m-d H:i:s');

        $connection->insert('core_company', [
            'code' => $code,
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return SeedResult::seeded((int) $connection->lastInsertId());
    }
}
