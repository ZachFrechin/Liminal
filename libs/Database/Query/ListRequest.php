<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Query;

/**
 * What the user asked a list for, once it has been checked against what the
 * list admits. Everything here is already valid: an unknown sort key, a
 * misspelt direction and an unlisted filter value are dropped during parsing
 * rather than carried and refused later — a hand-edited URL should show the
 * list, not an error page.
 *
 * toQueryParams() OMITS the defaults. That is what keeps `?page=2` from
 * growing into `?page=2&sort=name&dir=asc&q=`, and it puts `page` first
 * because the pagination links are the ones a human reads.
 */
final readonly class ListRequest
{
    /**
     * @param array<string, string> $filters filter key => chosen value, both
     *                                       already known to the schema
     */
    private function __construct(
        public int $page,
        public ?string $search,
        public string $sort,
        public SortDirection $direction,
        public array $filters,
    ) {}

    /**
     * @param array<array-key, mixed> $params the raw query string
     */
    public static function fromQueryParams(array $params, ListSchema $schema): self
    {
        $rawPage = $params['page'] ?? null;
        // Clamped low here, high in the builder: the upper bound is the page
        // count, and nothing knows it before the COUNT has run.
        $page = is_numeric($rawPage) ? max(1, (int) $rawPage) : 1;

        $rawSearch = $params['q'] ?? null;
        $search = is_string($rawSearch) && trim($rawSearch) !== '' ? trim($rawSearch) : null;

        $rawSort = $params['sort'] ?? null;
        $sort = is_string($rawSort) && $schema->sorts($rawSort) ? $rawSort : $schema->defaultSort;

        $direction = SortDirection::tryParse($params['dir'] ?? null) ?? $schema->defaultDirection;

        $filters = [];
        foreach ($schema->filters as $key => $filter) {
            $value = $params[$key] ?? null;

            if (is_string($value) && $filter->accepts($value)) {
                $filters[$key] = $value;
            }
        }

        return new self($page, $search, $sort, $direction, $filters);
    }

    /**
     * The first page of a list, as its schema declares it — for the callers
     * that page without a request to read (a console command, a test).
     */
    public static function first(ListSchema $schema): self
    {
        return new self(1, null, $schema->defaultSort, $schema->defaultDirection, []);
    }

    public function withPage(int $page): self
    {
        return new self(max(1, $page), $this->search, $this->sort, $this->direction, $this->filters);
    }

    /**
     * The same list read the other way round on the given column — the click
     * target of a sortable header. A different column starts ascending; the
     * current one flips. Sorting always returns to page one: page 7 of the
     * old order names different rows in the new one.
     */
    public function sortedBy(string $key, ListSchema $schema): self
    {
        if (!$schema->sorts($key)) {
            return $this;
        }

        $direction = $this->sort === $key
            ? $this->direction->opposite()
            : SortDirection::Ascending;

        return new self(1, $this->search, $key, $direction, $this->filters);
    }

    /**
     * @return array<string, string> defaults omitted, `page` first
     */
    public function toQueryParams(ListSchema $schema): array
    {
        $params = [];

        if ($this->page > 1) {
            $params['page'] = (string) $this->page;
        }

        if ($this->search !== null) {
            $params['q'] = $this->search;
        }

        if ($this->sort !== $schema->defaultSort) {
            $params['sort'] = $this->sort;
        }

        if ($this->direction !== $schema->defaultDirection) {
            $params['dir'] = $this->direction->value;
        }

        foreach ($this->filters as $key => $value) {
            $params[$key] = $value;
        }

        return $params;
    }

    public function filterValue(string $key): ?string
    {
        return $this->filters[$key] ?? null;
    }

    public function isFiltered(): bool
    {
        return $this->search !== null || $this->filters !== [];
    }
}
