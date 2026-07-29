<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Session;

use DateTimeImmutable;

/**
 * One request's session: a mutable bag over the stored payload, plus the two
 * values that get their own columns (user and company) because the database
 * must be able to query them.
 *
 * Dirty tracking is what makes read-only traffic free: an untouched session
 * whose last_seen_at is still fresh writes nothing at all. The raw id lives
 * here and only here — the database stores its SHA-256.
 */
final class Session
{
    /** @var array<string, mixed> */
    private array $payload;

    private bool $dirty;

    private ?string $staleId = null;

    private bool $destroyed = false;

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        private string $id,
        private readonly bool $new,
        array $payload,
        private ?int $userId,
        private ?int $companyId,
        private DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $lastSeenAt,
        bool $dirty,
    ) {
        $this->payload = $payload;
        $this->dirty = $dirty;
    }

    public static function fresh(string $id, DateTimeImmutable $now): self
    {
        // Not dirty yet: a fresh session nothing writes to leaves no row and
        // no cookie behind.
        return new self($id, true, [], null, null, $now, $now, false);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function hydrated(
        string $id,
        array $payload,
        ?int $userId,
        ?int $companyId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $lastSeenAt,
    ): self {
        return new self($id, false, $payload, $userId, $companyId, $createdAt, $lastSeenAt, false);
    }

    public function get(string $key): mixed
    {
        return $this->payload[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->payload);
    }

    public function set(string $key, mixed $value): void
    {
        $this->payload[$key] = $value;
        $this->dirty = true;
    }

    public function remove(string $key): void
    {
        if (array_key_exists($key, $this->payload)) {
            unset($this->payload[$key]);
            $this->dirty = true;
        }
    }

    /**
     * Read-and-forget: the flash primitive rendering will build on.
     */
    public function pull(string $key): mixed
    {
        $value = $this->get($key);
        $this->remove($key);

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->payload;
    }

    public function clear(): void
    {
        if ($this->payload !== []) {
            $this->payload = [];
            $this->dirty = true;
        }
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(?int $userId): void
    {
        if ($this->userId !== $userId) {
            $this->userId = $userId;
            $this->dirty = true;
        }
    }

    public function companyId(): ?int
    {
        return $this->companyId;
    }

    public function setCompanyId(?int $companyId): void
    {
        if ($this->companyId !== $companyId) {
            $this->companyId = $companyId;
            $this->dirty = true;
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isNew(): bool
    {
        return $this->new || $this->staleId !== null;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * The id this session had before regeneration; its row is deleted in the
     * same transaction that inserts the new one.
     */
    public function staleId(): ?string
    {
        return $this->staleId;
    }

    public function isDestroyed(): bool
    {
        return $this->destroyed;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastSeenAt(): DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    /**
     * Called by the SessionManager only. The payload survives on purpose —
     * the id is transport identity, the payload is state — and created_at
     * resets so the absolute cap counts from this privilege change.
     */
    public function replaceId(string $id, DateTimeImmutable $now): void
    {
        $this->staleId ??= $this->id;
        $this->id = $id;
        $this->createdAt = $now;
        $this->dirty = true;
    }

    /**
     * Called by the SessionManager only; honoured at persist time.
     */
    public function markDestroyed(): void
    {
        $this->destroyed = true;
        $this->dirty = true;
    }
}
