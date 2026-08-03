<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Query;

use Doctrine\ORM\QueryBuilder;
use Liminal\Lib\Database\Exception\DatabaseException;
use Liminal\Lib\Database\Pagination\Page;

/**
 * Turns a repository's own QueryBuilder into one page of results: the search
 * predicate, the declared filters, the whitelisted ORDER BY, the COUNT and
 * the clamp, once, for every list in the tree.
 *
 * The repository keeps what only it can know — the entity, the joins, and
 * above all the company narrowing, which is NOT this class's to add. A list
 * builder that fenced queries itself would be a second security boundary
 * competing with the one in the repositories, and the day the two disagreed
 * the quiet one would win.
 *
 * The page number is clamped AFTER counting: total=0 must land on page 1 of 1
 * (never page 0 and a negative offset), and a stale link to page 12 of what
 * is now 3 pages lands on page 3.
 */
final readonly class DqlListBuilder
{
    /**
     * @template T of object
     *
     * @param class-string<T> $entity what the builder selects; results that
     *                                are not of this class are a wiring bug,
     *                                not a runtime possibility
     *
     * @return Page<T>
     *
     * @throws DatabaseException when the query builder returns rows of another class
     */
    public function paginate(QueryBuilder $builder, string $entity, ListRequest $request, ListSchema $schema): Page
    {
        $this->constrain($builder, $request, $schema);

        // Cloned before the ordering and the window are applied: a COUNT with
        // an ORDER BY is refused by DQL, and a COUNT with a LIMIT would count
        // the page instead of the list.
        $counter = (clone $builder)
            ->select($schema->countExpression())
            ->resetDQLPart('orderBy');

        $total = (int) $counter->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / $schema->perPage));
        $page = min($request->page, $pages);

        // Pair by pair: orderBy() appends its own ASC to whatever it is
        // handed, so a whole clause comes out as "i.id DESC ASC".
        foreach ($schema->ordering($request->sort, $request->direction) as $index => [$expression, $keyword]) {
            $index === 0
                ? $builder->orderBy($expression, $keyword)
                : $builder->addOrderBy($expression, $keyword);
        }

        $rows = $builder
            ->setFirstResult(($page - 1) * $schema->perPage)
            ->setMaxResults($schema->perPage)
            ->getQuery()
            ->getResult();

        $items = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!$row instanceof $entity) {
                throw DatabaseException::unexpectedListResult($entity);
            }

            $items[] = $row;
        }

        return new Page($items, $total, $page, $pages, $schema->perPage);
    }

    /**
     * Search and filters — everything the request is allowed to add to the
     * WHERE. Both go through the schema: the needle is the only user value
     * that reaches the query, and it reaches it BOUND.
     */
    private function constrain(QueryBuilder $builder, ListRequest $request, ListSchema $schema): void
    {
        $needle = $schema->search === null ? null : LikeNeedle::wrap($request->search);

        if ($needle !== null && $schema->search !== null) {
            $builder->andWhere('(' . $schema->search . ')')->setParameter('q', $needle);
        }

        foreach ($request->filters as $key => $value) {
            $filter = $schema->filter($key);

            if ($filter === null) {
                continue;
            }

            // A code constant selected by key — see ListFilter on why this is
            // the one string concatenation in the layer that is safe.
            $builder->andWhere($filter->choices[$value]);
        }
    }
}
