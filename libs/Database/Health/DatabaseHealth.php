<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Health;

use Closure;
use Doctrine\DBAL\Connection;
use Liminal\Config\Configuration;
use Throwable;

/**
 * Answers "can the configured database be reached?" without ever making boot
 * depend on it: the connection arrives as a deferred closure, and the
 * unconfigured case is decided before that closure is ever invoked, so a
 * missing DSN can never crash anything — it is reported, not thrown.
 *
 * Deliberately not counting pending migrations here: Doctrine initialises its
 * metadata table on first contact, and a diagnostic must not mutate the
 * database it inspects.
 */
final readonly class DatabaseHealth
{
    /**
     * @param Closure(): Connection $connection deferred so an unconfigured DSN is never resolved
     */
    public function __construct(
        private Configuration $config,
        private Closure $connection,
    ) {}

    public function check(): DatabaseStatus
    {
        if (!$this->config->has('database.url')) {
            return DatabaseStatus::notConfigured();
        }

        try {
            $version = ($this->connection)()->fetchOne('SELECT VERSION()');

            return DatabaseStatus::ok(is_string($version) ? $version : 'unknown');
        } catch (Throwable $exception) {
            return DatabaseStatus::unreachable($exception->getMessage());
        }
    }
}
