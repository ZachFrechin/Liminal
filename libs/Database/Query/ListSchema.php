<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Query;

use Liminal\Lib\Database\Exception\DatabaseException;

/**
 * What a list will admit: which columns may be sorted on, which filters
 * exist, what it searches, how many rows a page holds.
 *
 * The sortable columns are a WHITELIST mapping a public key to a DQL
 * expression, and that is a security boundary, not a convenience. A sort key
 * is concatenated into ORDER BY — bound parameters cannot appear there — so
 * the only safe design is one where the request selects among expressions the
 * module wrote, and an unknown key falls back to the default instead of
 * reaching the query.
 *
 * The tiebreaker is DECLARED rather than assumed: a sort on a joined alias
 * still needs the root's id to break ties, or two rows with the same party
 * name swap between pages on MariaDB and one of them is never seen. Callers
 * name the root's id explicitly for the same reason the ORDER BY does.
 */
final readonly class ListSchema
{
    /**
     * @param non-empty-array<string, string> $sorts        public key => DQL expression
     * @param array<string, ListFilter>       $filters      keyed by the filter's own key
     * @param non-empty-string                $tiebreaker   DQL expression, the root's id
     * @param ?string                         $search       DQL predicate binding :q, or null
     *                                                      when the list has no search box
     * @param bool                            $countDistinct COUNT(DISTINCT id) instead of
     *                                                       COUNT(id) — mandatory as soon as
     *                                                       a join can multiply rows
     *
     * @throws DatabaseException when the declaration contradicts itself
     */
    public function __construct(
        public string $alias,
        public array $sorts,
        public string $tiebreaker,
        public string $defaultSort,
        public SortDirection $defaultDirection,
        public int $perPage,
        public ?string $search = null,
        public array $filters = [],
        public bool $countDistinct = false,
    ) {
        if (!isset($this->sorts[$defaultSort])) {
            throw DatabaseException::unknownDefaultSort($defaultSort);
        }

        if ($perPage < 1) {
            throw DatabaseException::nonPositivePageSize($perPage);
        }

        foreach ($filters as $key => $filter) {
            if ($key !== $filter->key) {
                throw DatabaseException::misfiledFilter($key, $filter->key);
            }
        }
    }

    public function sorts(string $key): bool
    {
        return isset($this->sorts[$key]);
    }

    /**
     * The ordering for a validated sort key, as PAIRS rather than a clause:
     * a Doctrine QueryBuilder takes the expression and the direction
     * separately and appends its own ASC to anything it is handed whole. The
     * pairs are also what lets the DBAL side render the same declaration its
     * own way.
     *
     * The tiebreaker follows in the SAME direction, so a descending page
     * reads whole. A list already sorted BY its tiebreaker names it once.
     *
     * @return non-empty-list<array{string, string}> expression, keyword
     */
    public function ordering(string $key, SortDirection $direction): array
    {
        $expression = $this->sorts[$key] ?? $this->sorts[$this->defaultSort];
        $keyword = $direction->keyword();

        if ($expression === $this->tiebreaker) {
            return [[$expression, $keyword]];
        }

        return [[$expression, $keyword], [$this->tiebreaker, $keyword]];
    }

    /**
     * The same ordering as one clause, for the callers that take a string.
     */
    public function orderByClause(string $key, SortDirection $direction): string
    {
        return implode(', ', array_map(
            static fn(array $pair): string => $pair[0] . ' ' . $pair[1],
            $this->ordering($key, $direction),
        ));
    }

    public function filter(string $key): ?ListFilter
    {
        return $this->filters[$key] ?? null;
    }

    public function countExpression(): string
    {
        return $this->countDistinct
            ? 'COUNT(DISTINCT ' . $this->tiebreaker . ')'
            : 'COUNT(' . $this->tiebreaker . ')';
    }
}
