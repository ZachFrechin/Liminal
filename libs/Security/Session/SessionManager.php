<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Session;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Random\RandomException;

/**
 * Loads, persists and expires database-backed sessions.
 *
 * Three properties are deliberate and load-bearing:
 *
 * - A presented id is NEVER adopted: ids are only ever server-generated, so
 *   session fixation by cookie injection is structurally impossible.
 * - The row is keyed by sha256(cookie); the raw value exists only in the
 *   cookie and in memory.
 * - Deletion beats concurrent writers. An existing session persists with
 *   UPDATE and a zero-row result is accepted silently: if the row died
 *   meanwhile (logout, regeneration, GC), a blind upsert would RESURRECT it —
 *   reopening the very fixation window regeneration closes.
 *
 * The connection is deferred because `session:gc` is a console command and the
 * console resolves every command eagerly.
 */
final readonly class SessionManager
{
    /**
     * Only refresh last_seen_at when it is this stale, so a read-only request
     * inside the window costs zero writes.
     */
    private const int TOUCH_INTERVAL_SECONDS = 60;

    /** Cookie values are 32 random bytes, base64url — always 43 characters. */
    private const string ID_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param Closure(): Connection $connection deferred so session:gc resolves on a DSN-less checkout
     */
    public function __construct(
        private Closure $connection,
        private string $cookieName,
        private int $idleTtlSeconds,
        private int $absoluteTtlSeconds,
        private bool $secureCookie,
        private int $gcPercent,
    ) {}

    /**
     * @throws RandomException when the platform cannot produce secure randomness
     */
    public function start(ServerRequestInterface $request): Session
    {
        $now = new DateTimeImmutable();
        $presented = $request->getCookieParams()[$this->cookieName] ?? null;

        if (!is_string($presented) || preg_match(self::ID_PATTERN, $presented) !== 1) {
            // No engaged client: no lookup, no GC lottery — a cookieless
            // request costs zero database work, so a DSN-less checkout still
            // serves its public pages.
            return Session::fresh($this->newId(), $now);
        }

        $this->maybeCollect();

        $row = ($this->connection)()->fetchAssociative(
            'SELECT payload, user_id, company_id, created_at, last_seen_at
             FROM core_session
             WHERE id = ? AND expires_at > ?',
            [self::hash($presented), $now->format(self::TIMESTAMP_FORMAT)],
        );

        if ($row === false) {
            // Unknown or expired: a new id, never the presented one.
            return Session::fresh($this->newId(), $now);
        }

        $payload = $this->decodePayload($row['payload'] ?? null);
        $createdAt = self::toDateTime($row['created_at'] ?? null);
        $lastSeenAt = self::toDateTime($row['last_seen_at'] ?? null);

        if ($payload === null || $createdAt === null || $lastSeenAt === null) {
            // A corrupt row must degrade to a fresh session, never to a 500.
            return Session::fresh($this->newId(), $now);
        }

        return Session::hydrated(
            $presented,
            $payload,
            self::toNullableInt($row['user_id'] ?? null),
            self::toNullableInt($row['company_id'] ?? null),
            $createdAt,
            $lastSeenAt,
        );
    }

    /**
     * @throws JsonException when the payload cannot be encoded
     */
    public function persist(Session $session, ResponseInterface $response): ResponseInterface
    {
        $now = new DateTimeImmutable();

        if ($session->isDestroyed()) {
            ($this->connection)()->delete('core_session', ['id' => self::hash($session->staleId() ?? $session->id())]);

            return $this->withCookie($response, '', 0);
        }

        if ($session->isNew()) {
            if (!$session->isDirty()) {
                // Nothing was written: no row, no cookie — and, crucially, no
                // connection resolved: cookieless traffic must stay serveable
                // on a DSN-less checkout.
                return $response;
            }

            $this->insert(($this->connection)(), $session, $now);

            return $this->withCookie($response, $session->id(), null);
        }

        $stale = $now->getTimestamp() - $session->lastSeenAt()->getTimestamp() >= self::TOUCH_INTERVAL_SECONDS;

        if (!$session->isDirty() && !$stale) {
            return $response;
        }

        // Zero rows means the row died meanwhile; accepting that silently is
        // what stops a concurrent request from resurrecting a killed session.
        ($this->connection)()->executeStatement(
            'UPDATE core_session
             SET payload = ?, user_id = ?, company_id = ?, last_seen_at = ?, expires_at = ?
             WHERE id = ?',
            [
                self::encodePayload($session),
                $session->userId(),
                $session->companyId(),
                $now->format(self::TIMESTAMP_FORMAT),
                $this->expiryFor($session->createdAt(), $now)->format(self::TIMESTAMP_FORMAT),
                self::hash($session->id()),
            ],
        );

        return $response;
    }

    /**
     * Renews the id in memory only; persist() performs the delete-then-insert
     * atomically, so an aborted request leaves the previous session intact.
     *
     * @throws RandomException when the platform cannot produce secure randomness
     */
    public function regenerate(Session $session): void
    {
        $session->replaceId($this->newId(), new DateTimeImmutable());
    }

    public function destroy(Session $session): void
    {
        $session->markDestroyed();
    }

    /**
     * @return int the number of expired sessions removed
     */
    public function gc(): int
    {
        $deleted = ($this->connection)()->executeStatement(
            'DELETE FROM core_session WHERE expires_at < ?',
            [new DateTimeImmutable()->format(self::TIMESTAMP_FORMAT)],
        );

        return (int) $deleted;
    }

    /**
     * @throws JsonException
     */
    private function insert(Connection $connection, Session $session, DateTimeImmutable $now): void
    {
        $stale = $session->staleId();
        $values = [
            'id' => self::hash($session->id()),
            'user_id' => $session->userId(),
            'company_id' => $session->companyId(),
            'payload' => self::encodePayload($session),
            'created_at' => $session->createdAt()->format(self::TIMESTAMP_FORMAT),
            'last_seen_at' => $now->format(self::TIMESTAMP_FORMAT),
            'expires_at' => $this->expiryFor($session->createdAt(), $now)->format(self::TIMESTAMP_FORMAT),
        ];

        if ($stale === null) {
            $connection->insert('core_session', $values);

            return;
        }

        // Regeneration: the old id must die in the same transaction that
        // creates the new one — never a window where both or neither exist.
        $connection->transactional(static function (Connection $connection) use ($stale, $values): void {
            $connection->delete('core_session', ['id' => self::hash($stale)]);
            $connection->insert('core_session', $values);
        });
    }

    /**
     * Both OWASP timeouts in one value: whichever comes first wins.
     */
    private function expiryFor(DateTimeImmutable $createdAt, DateTimeImmutable $now): DateTimeImmutable
    {
        $idle = $now->modify(sprintf('+%d seconds', $this->idleTtlSeconds));
        $absolute = $createdAt->modify(sprintf('+%d seconds', $this->absoluteTtlSeconds));

        return $idle < $absolute ? $idle : $absolute;
    }

    private function withCookie(ResponseInterface $response, string $value, ?int $maxAge): ResponseInterface
    {
        // A browser-session cookie by design: the database enforces both TTLs,
        // and dying with the browser is strictly safer on shared machines.
        $parts = [sprintf('%s=%s', $this->cookieName, $value), 'Path=/', 'HttpOnly', 'SameSite=Lax'];

        if ($maxAge !== null) {
            $parts[] = sprintf('Max-Age=%d', $maxAge);
            $parts[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        }

        if ($this->secureCookie) {
            $parts[] = 'Secure';
        }

        return $response->withAddedHeader('Set-Cookie', implode('; ', $parts));
    }

    /**
     * @throws RandomException
     */
    private function newId(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function maybeCollect(): void
    {
        if ($this->gcPercent <= 0) {
            return;
        }

        if (random_int(1, 100) <= $this->gcPercent) {
            $this->gc();
        }
    }

    private static function hash(string $id): string
    {
        return hash('sha256', $id);
    }

    /**
     * @throws JsonException
     */
    private static function encodePayload(Session $session): string
    {
        return json_encode($session->all(), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>|null null when the stored payload is unusable
     */
    private function decodePayload(mixed $raw): ?array
    {
        if (!is_string($raw)) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $payload = [];

        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                return null;
            }

            $payload[$key] = $value;
        }

        return $payload;
    }

    private static function toDateTime(mixed $raw): ?DateTimeImmutable
    {
        if (!is_string($raw)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(self::TIMESTAMP_FORMAT, $raw);

        return $parsed === false ? null : $parsed;
    }

    private static function toNullableInt(mixed $raw): ?int
    {
        // Drivers may hand back numeric strings; only a real NULL is null.
        return is_numeric($raw) ? (int) $raw : null;
    }
}
