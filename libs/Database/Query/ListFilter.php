<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Query;

use Liminal\Lib\Database\Exception\DatabaseException;

/**
 * One declared filter: a query key, and the closed set of values it accepts,
 * each mapped to the predicate it adds.
 *
 * The predicates are CODE CONSTANTS chosen by a key lookup, never assembled
 * from the request. That is the whole design: a filter cannot express
 * anything its module did not write down, so a hand-edited `?status=' OR 1=1`
 * finds no entry and is dropped — there is no parameter to bind and no
 * fragment to inject. It also means a filter needs no escaping story, which
 * is why free-text filtering is deliberately absent here: it would need one,
 * and inventing it before a screen asks for it is how injection surfaces get
 * built.
 */
final readonly class ListFilter
{
    /**
     * @param non-empty-array<string, string> $choices accepted query value =>
     *                                                 the DQL predicate it adds
     * @param non-empty-string                $labelPrefix catalogue key stem;
     *                                                     each choice renders
     *                                                     as "<prefix>.<value>"
     *
     * @throws DatabaseException when a choice carries no predicate
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $labelPrefix,
        public array $choices,
    ) {
        foreach ($choices as $value => $predicate) {
            if (trim($predicate) === '') {
                throw DatabaseException::emptyFilterPredicate($key, $value);
            }
        }
    }

    public function accepts(string $value): bool
    {
        return isset($this->choices[$value]);
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_keys($this->choices);
    }

    public function labelFor(string $value): string
    {
        return $this->labelPrefix . '.' . $value;
    }
}
