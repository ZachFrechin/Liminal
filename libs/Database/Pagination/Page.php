<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Pagination;

/**
 * One page of a bounded list. Born module-local in the thirdparty module (the
 * tree's first pagination) and hoisted here the day the second list needed it
 * — exactly as recorded. Repositories keep their own PER_PAGE constant: the
 * page size is a property of each list, not of pagination itself.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $pages,
        public int $perPage,
    ) {}

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages;
    }
}
