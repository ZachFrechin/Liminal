<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Administration;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * The write side of API tokens: minting, listing, revoking — DBAL like every
 * administration service. The raw token exists exactly once, in the
 * MintedToken returned to the caller for one-time display; only its sha256
 * ever reaches storage, so there is no "show me the token again" and never
 * will be.
 *
 * Revocation is deletion: a bearer credential has no disabled state worth
 * auditing separately from its absence, and the row carries no history the
 * audit trail does not already hold through TOKEN_CREATED/TOKEN_REVOKED.
 */
final readonly class TokenAdministration
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param Closure(): Connection $connection deferred: commands resolve eagerly
     */
    public function __construct(private Closure $connection) {}

    /**
     * Mints a token for a user and returns the ONLY copy of the raw value.
     * The liminal_ prefix makes leaked tokens findable by secret scanners.
     */
    public function mint(int $userId, string $label): MintedToken
    {
        $raw = 'liminal_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $connection = ($this->connection)();

        $connection->insert('core_api_token', [
            'token_hash' => hash('sha256', $raw),
            'user_id' => $userId,
            'label' => $label,
            'created_at' => new DateTimeImmutable()->format(self::TIMESTAMP_FORMAT),
        ]);

        return new MintedToken((int) $connection->lastInsertId(), $raw);
    }

    /**
     * @return list<array{id: int, label: string, created_at: string, last_used_at: ?string}>
     */
    public function listFor(int $userId): array
    {
        $rows = ($this->connection)()->fetchAllAssociative(
            'SELECT id, label, created_at, last_used_at FROM core_api_token WHERE user_id = ? ORDER BY id',
            [$userId],
        );

        return array_map(static fn(array $row): array => [
            'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
            'label' => is_string($row['label']) ? $row['label'] : '',
            'created_at' => is_string($row['created_at']) ? $row['created_at'] : '',
            'last_used_at' => is_string($row['last_used_at']) ? $row['last_used_at'] : null,
        ], $rows);
    }

    /**
     * Deletes one token. With $ownedBy, only when it belongs to that user —
     * the self-service screen's guard; the console omits it, an operator
     * gesture like every other command.
     */
    public function revoke(int $id, ?int $ownedBy = null): ?int
    {
        $connection = ($this->connection)();

        $owner = $connection->fetchOne('SELECT user_id FROM core_api_token WHERE id = ?', [$id]);

        if (!is_numeric($owner)) {
            return null;
        }

        $userId = (int) $owner;

        if ($ownedBy !== null && $userId !== $ownedBy) {
            return null;
        }

        $connection->delete('core_api_token', ['id' => $id]);

        return $userId;
    }
}
