<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Repository;

use Liminal\Module\Thirdparty\Entity\Thirdparty;

/**
 * One page of the thirdparty list — the tree's first pagination, deliberately
 * module-local: it gets hoisted into a lib the day a second list needs it,
 * not before.
 */
final readonly class ThirdpartyPage
{
    public const int PER_PAGE = 25;

    /**
     * @param list<Thirdparty> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $pages,
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
