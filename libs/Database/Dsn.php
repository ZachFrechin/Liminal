<?php

declare(strict_types=1);

namespace Liminal\Lib\Database;

use Doctrine\DBAL\Tools\DsnParser;

final class Dsn
{
    /**
     * DsnParser passes unmapped schemes through verbatim and "mysql" is not a DBAL
     * driver name, so this mapping is required for a URL-style DSN to connect.
     */
    private const SCHEME_MAPPING = [
        'mysql' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'sqlite' => 'pdo_sqlite',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function parse(string $dsn): array
    {
        return (new DsnParser(self::SCHEME_MAPPING))->parse($dsn);
    }
}
