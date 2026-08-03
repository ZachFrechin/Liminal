<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Query;

/**
 * The two directions a column can be read in, and the only two strings that
 * ever reach an ORDER BY. An enum rather than a validated string because the
 * direction is concatenated into DQL: what cannot be constructed cannot be
 * injected.
 */
enum SortDirection: string
{
    case Ascending = 'asc';

    case Descending = 'desc';

    /**
     * Anything unrecognised is not an error — a hand-edited URL is not worth a
     * 500. The caller falls back to the list's declared default.
     */
    public static function tryParse(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;
    }

    /**
     * The DQL keyword. Two code constants; no user string reaches this.
     */
    public function keyword(): string
    {
        return $this === self::Ascending ? 'ASC' : 'DESC';
    }

    public function opposite(): self
    {
        return $this === self::Ascending ? self::Descending : self::Ascending;
    }
}
