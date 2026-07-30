<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Scope;

use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The names behind company ids — one bounded DBAL read, for surfaces that
 * live below the modules (the shell's company switcher renders on every
 * page, and the rendering lib cannot lean on a module service).
 *
 * DBAL, never the ORM: this is a request-path read on the security
 * boundary's vocabulary, and the company switch clears the EntityManager
 * after auth — the phase-5a rule. The connection is deferred so resolving
 * consumers without a DSN stays legal; a caller with no ids to ask about
 * never touches it at all.
 */
final readonly class CompanyDirectory
{
    /**
     * @param Closure(): Connection $connection
     */
    public function __construct(private Closure $connection) {}

    /**
     * A company deleted between the grant read and this render simply drops
     * off the list — content degrades, the switch handler revalidates anyway.
     *
     * @param list<int> $ids
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<array{id: int|string, code: string, name: string}> $rows */
        $rows = ($this->connection)()->fetchAllAssociative(
            'SELECT id, code, name FROM core_company WHERE id IN (:ids) ORDER BY code',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'code' => $row['code'],
                'name' => $row['name'],
            ],
            $rows,
        );
    }
}
