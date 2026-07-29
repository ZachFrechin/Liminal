<?php

declare(strict_types=1);

namespace Liminal\Lib\Database;

use Closure;
use Doctrine\DBAL\Connection;
use Liminal\Lib\Database\Exception\DatabaseException;
use Psr\Container\ContainerInterface;

/**
 * The one place the deferred-connection closure is built: every service that
 * must stay resolvable on a DSN-less checkout takes its Connection through
 * this resolver, because the console resolves every registered command
 * eagerly and an eager Connection would break even `doctor`.
 */
final class DeferredConnection
{
    private function __construct() {}

    /**
     * @return Closure(): Connection
     */
    public static function resolver(ContainerInterface $container): Closure
    {
        return static function () use ($container): Connection {
            $connection = $container->get(Connection::class);

            if (!$connection instanceof Connection) {
                throw DatabaseException::unexpectedConnectionType();
            }

            return $connection;
        };
    }
}
