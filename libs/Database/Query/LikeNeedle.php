<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Query;

/**
 * The search needle, hoisted verbatim from the third list that wrote it.
 *
 * A bound parameter escapes NOTHING: % and _ inside the VALUE still act as
 * wildcards, so the user's text is escaped before the wrapping ones are
 * added. The escape character is '!' rather than a backslash — a backslash
 * would have to survive PHP, DQL and SQL quoting in agreement, and MariaDB
 * refuses what comes out the other end. Every predicate that binds :q must
 * therefore spell ESCAPE '!' explicitly; the constant here is the reason it
 * is not a matter of taste.
 */
final readonly class LikeNeedle
{
    /** Longer than any legitimate search, short enough to bound the LIKE. */
    public const int MAX_QUERY_LENGTH = 100;

    /** The character every predicate binding :q must name in its ESCAPE. */
    public const string ESCAPE = '!';

    /**
     * The bound value for a LIKE, or null when there is nothing to search —
     * null is the signal to leave the predicate out entirely rather than
     * match everything with '%%'.
     */
    public static function wrap(?string $query): ?string
    {
        $query = trim($query ?? '');

        if ($query === '') {
            return null;
        }

        $escaped = str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE . self::ESCAPE, self::ESCAPE . '%', self::ESCAPE . '_'],
            mb_substr($query, 0, self::MAX_QUERY_LENGTH),
        );

        return '%' . $escaped . '%';
    }
}
