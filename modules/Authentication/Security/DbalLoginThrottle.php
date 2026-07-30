<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Security;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Liminal\Lib\Security\Authentication\ClientContext;
use Liminal\Lib\Security\Contract\LoginThrottle;

/**
 * Fixed window plus lockout, counted per subject in core_login_attempt.
 *
 * Two subjects per attempt — the identifier and the client address — because
 * either alone is trivially defeated: rotating identifiers defeats the first,
 * and a botnet defeats the second. State lives in the database rather than the
 * session because a failed request persists nothing and an attacker who drops
 * the cookie would otherwise reset the counter.
 *
 * No progressive delay: holding a PHP worker in sleep() is a self-inflicted
 * denial of service. A locked subject is refused immediately instead.
 */
final readonly class DbalLoginThrottle implements LoginThrottle
{
    private const string KIND_IDENTIFIER = 'identifier';

    private const string KIND_ADDRESS = 'address';

    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param Closure(): Connection $connection
     */
    public function __construct(
        private Closure $connection,
        private int $maxFailures,
        private int $addressMaxFailures,
        private int $windowSeconds,
        private int $lockoutSeconds,
    ) {}

    public function check(string $identifier, ClientContext $client): ?int
    {
        $now = new DateTimeImmutable();

        $rows = ($this->connection)()->fetchAllAssociative(
            'SELECT locked_until FROM core_login_attempt
             WHERE (kind = ? AND subject = ?) OR (kind = ? AND subject = ?)',
            [
                self::KIND_IDENTIFIER,
                $this->subject($identifier),
                self::KIND_ADDRESS,
                $client->ipAddress ?? '',
            ],
        );

        $longest = null;

        foreach ($rows as $row) {
            $lockedUntil = $row['locked_until'] ?? null;

            if (!is_string($lockedUntil)) {
                continue;
            }

            $until = DateTimeImmutable::createFromFormat(self::TIMESTAMP_FORMAT, $lockedUntil);

            if ($until === false) {
                continue;
            }

            $remaining = $until->getTimestamp() - $now->getTimestamp();

            if ($remaining > 0 && ($longest === null || $remaining > $longest)) {
                $longest = $remaining;
            }
        }

        return $longest;
    }

    public function recordFailure(string $identifier, ClientContext $client): void
    {
        $this->count(self::KIND_IDENTIFIER, $this->subject($identifier), $this->maxFailures);

        if ($client->ipAddress !== null) {
            $this->count(self::KIND_ADDRESS, $client->ipAddress, $this->addressMaxFailures);
        }
    }

    /**
     * Clears the identifier counter ONLY. Clearing the address counter as well
     * would let an attacker spray twenty accounts from one address, log into
     * their own, and reset the spray.
     */
    public function recordSuccess(string $identifier, ClientContext $client): void
    {
        ($this->connection)()->delete('core_login_attempt', [
            'kind' => self::KIND_IDENTIFIER,
            'subject' => $this->subject($identifier),
        ]);
    }

    /**
     * Three plain statements in one transaction rather than a clever
     * ON DUPLICATE KEY UPDATE with conditionals: MySQL evaluates those
     * assignments left to right against already-updated values, which makes the
     * expression both fragile and unreviewable.
     */
    private function count(string $kind, string $subject, int $threshold): void
    {
        $now = new DateTimeImmutable();
        $nowText = $now->format(self::TIMESTAMP_FORMAT);
        $windowStart = $now->modify(sprintf('-%d seconds', $this->windowSeconds))->format(self::TIMESTAMP_FORMAT);
        $lockUntil = $now->modify(sprintf('+%d seconds', $this->lockoutSeconds))->format(self::TIMESTAMP_FORMAT);

        ($this->connection)()->transactional(
            static function (Connection $connection) use ($kind, $subject, $threshold, $nowText, $windowStart, $lockUntil): void {
                // A fully elapsed window is dropped so the counter restarts at 1.
                $connection->executeStatement(
                    'DELETE FROM core_login_attempt
                     WHERE kind = ? AND subject = ? AND first_at < ?
                       AND (locked_until IS NULL OR locked_until < ?)',
                    [$kind, $subject, $windowStart, $nowText],
                );

                $connection->executeStatement(
                    'INSERT INTO core_login_attempt (kind, subject, failures, first_at, last_at)
                     VALUES (?, ?, 1, ?, ?)
                     ON DUPLICATE KEY UPDATE failures = failures + 1, last_at = VALUES(last_at)',
                    [$kind, $subject, $nowText, $nowText],
                );

                // Lock on threshold, and never extend a lock that is still live.
                $connection->executeStatement(
                    'UPDATE core_login_attempt SET locked_until = ?
                     WHERE kind = ? AND subject = ? AND failures >= ?
                       AND (locked_until IS NULL OR locked_until < ?)',
                    [$lockUntil, $kind, $subject, $threshold, $nowText],
                );
            },
        );
    }

    /**
     * The same normalisation the user lookup uses, so "Alice@x" and "alice@x"
     * cannot each carry their own counter.
     */
    private function subject(string $identifier): string
    {
        return mb_substr(DbalUserProvider::normalise($identifier), 0, 191);
    }
}
