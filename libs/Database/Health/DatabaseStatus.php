<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Health;

/**
 * Outcome of one DatabaseHealth::check(): a kind plus the human detail the
 * doctor prints next to it (server version, failure reason, or remedy).
 */
final readonly class DatabaseStatus
{
    private function __construct(
        public DatabaseStatusKind $kind,
        public string $detail,
    ) {}

    public static function notConfigured(): self
    {
        return new self(DatabaseStatusKind::NotConfigured, 'no DSN configured (set LIMINAL_DSN)');
    }

    public static function unreachable(string $reason): self
    {
        return new self(DatabaseStatusKind::Unreachable, $reason);
    }

    public static function ok(string $serverVersion): self
    {
        return new self(DatabaseStatusKind::Ok, $serverVersion);
    }
}
