<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Security;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Liminal\Lib\Security\Contract\TokenProvider;
use SensitiveParameter;

/**
 * Validates bearer credentials straight through DBAL, never the ORM — the
 * request-path security rule. Hashing is this class's business alone: the
 * raw token is hashed once and the digest looked up through the unique
 * index, the exact session posture.
 *
 * last_used_at is touched at most once a minute (the session
 * TOUCH_INTERVAL precedent): the row itself is the throttle state — the
 * SELECT already carries the previous value, so a fresh one costs no write.
 */
final readonly class DbalTokenProvider implements TokenProvider
{
    private const int TOUCH_INTERVAL_SECONDS = 60;

    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param Closure(): Connection $connection deferred: commands resolve eagerly
     */
    public function __construct(private Closure $connection) {}

    public function authenticate(#[SensitiveParameter] string $rawToken): ?int
    {
        $connection = ($this->connection)();

        $row = $connection->fetchAssociative(
            'SELECT id, user_id, last_used_at, expires_at FROM core_api_token WHERE token_hash = ?',
            [hash('sha256', $rawToken)],
        );

        if ($row === false) {
            return null;
        }

        $now = new DateTimeImmutable();

        if (is_string($row['expires_at']) && new DateTimeImmutable($row['expires_at']) <= $now) {
            return null;
        }

        $lastUsed = is_string($row['last_used_at']) ? new DateTimeImmutable($row['last_used_at']) : null;

        if ($lastUsed === null || $now->getTimestamp() - $lastUsed->getTimestamp() >= self::TOUCH_INTERVAL_SECONDS) {
            $connection->update(
                'core_api_token',
                ['last_used_at' => $now->format(self::TIMESTAMP_FORMAT)],
                ['id' => $row['id']],
            );
        }

        return is_numeric($row['user_id']) ? (int) $row['user_id'] : null;
    }
}
