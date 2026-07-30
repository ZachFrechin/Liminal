<?php

declare(strict_types=1);

namespace Liminal\Module\Companies\Administration;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Liminal\Lib\Module\ModuleManager;

/**
 * The write side of company administration. Plain DBAL on a deferred
 * connection, like every production write in the tree.
 *
 * Creation closes the 5a obligation: a new company gets every declared AND
 * installed module enabled, in the SAME transaction as its row — a crash
 * between the two must not leave a company where every page 404s. Only
 * enable(), never install(): install() runs migrations, and DDL mid-request
 * is not a thing a web handler gets to do. Declared-but-not-installed modules
 * are reported by name so the operator knows to run `module:install`.
 */
final readonly class CompanyAdministration
{
    /**
     * Codes are SCREAMING_SNAKE, 1–32 characters, starting with a letter — a
     * policy, not an inherited constraint: the seeder accepts anything, and
     * the case-insensitive collation already collides MAIN with main. New
     * codes just get a grammar, existing rows keep whatever they have.
     */
    public const string CODE_PATTERN = '/^[A-Z][A-Z0-9_]{0,31}$/';

    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param Closure(): Connection $connection
     */
    public function __construct(
        private Closure $connection,
        private ModuleManager $modules,
    ) {}

    /**
     * @return list<array{id: int, code: string, name: string, createdAt: string}>
     */
    public function listAll(): array
    {
        $rows = ($this->connection)()->fetchAllAssociative(
            'SELECT id, code, name, created_at FROM core_company ORDER BY code',
        );

        $companies = [];

        foreach ($rows as $row) {
            $companies[] = [
                'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                'code' => is_string($row['code'] ?? null) ? $row['code'] : '',
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'createdAt' => is_string($row['created_at'] ?? null) ? $row['created_at'] : '',
            ];
        }

        return $companies;
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    public function companyById(int $id): ?array
    {
        $row = ($this->connection)()->fetchAssociative(
            'SELECT id, code, name FROM core_company WHERE id = ?',
            [$id],
        );

        if ($row === false) {
            return null;
        }

        return [
            'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            'code' => is_string($row['code'] ?? null) ? $row['code'] : '',
            'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
        ];
    }

    /**
     * @throws UniqueConstraintViolationException when the code is already taken
     */
    public function create(string $code, string $name): CompanyCreation
    {
        // Which declared modules are actually installed is read OUTSIDE the
        // transaction: overview() inspects module state, it does not lock it.
        $installed = [];
        $notInstalled = [];

        foreach ($this->modules->overview() as $module) {
            if ($module->installedVersion !== null) {
                $installed[] = $module->name;
            } elseif ($module->declaredVersion !== null) {
                $notInstalled[] = $module->name;
            }
        }

        $companyId = ($this->connection)()->transactional(
            function (Connection $connection) use ($code, $name, $installed): int {
                $now = new DateTimeImmutable()->format(self::TIMESTAMP_FORMAT);

                $connection->insert('core_company', [
                    'code' => $code,
                    'name' => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $companyId = (int) $connection->lastInsertId();

                // Same container Connection: these statements join the
                // transaction, so the company and its enablements land as one.
                foreach ($installed as $module) {
                    $this->modules->enable($module, $companyId);
                }

                return $companyId;
            },
        );

        return new CompanyCreation((int) $companyId, $installed, $notInstalled);
    }

    /**
     * The code never moves — commands, fixtures and habits anchor on it; the
     * display name is the mutable half, exactly like the entity's rename().
     *
     * @return bool false when no such company exists
     */
    public function rename(int $id, string $name): bool
    {
        return ($this->connection)()->update('core_company', [
            'name' => $name,
            'updated_at' => new DateTimeImmutable()->format(self::TIMESTAMP_FORMAT),
        ], ['id' => $id]) > 0;
    }
}
