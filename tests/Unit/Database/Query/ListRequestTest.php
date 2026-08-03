<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Database\Query;

use Liminal\Lib\Database\Query\ListFilter;
use Liminal\Lib\Database\Query\ListRequest;
use Liminal\Lib\Database\Query\ListSchema;
use Liminal\Lib\Database\Query\SortDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parsing half of the list layer: what a URL is allowed to ask for.
 *
 * The rule under everything here is that a hand-edited query string produces
 * a LIST, never an error and never a query it did not authorise. Sort keys,
 * directions and filter values are all checked against the schema and
 * silently replaced by the declared defaults when they do not match — which
 * is why the ORDER BY can safely be built by concatenation.
 */
#[CoversClass(ListRequest::class)]
#[CoversClass(ListSchema::class)]
#[CoversClass(SortDirection::class)]
final class ListRequestTest extends TestCase
{
    public function testTheDefaultsApplyToAnEmptyQueryString(): void
    {
        $request = ListRequest::fromQueryParams([], self::schema());

        self::assertSame(1, $request->page);
        self::assertNull($request->search);
        self::assertSame('name', $request->sort);
        self::assertSame(SortDirection::Ascending, $request->direction);
        self::assertSame([], $request->filters);
        self::assertFalse($request->isFiltered());
    }

    public function testEverythingValidIsKept(): void
    {
        $request = ListRequest::fromQueryParams(
            ['page' => '3', 'q' => 'acme', 'sort' => 'code', 'dir' => 'desc', 'status' => 'archived'],
            self::schema(),
        );

        self::assertSame(3, $request->page);
        self::assertSame('acme', $request->search);
        self::assertSame('code', $request->sort);
        self::assertSame(SortDirection::Descending, $request->direction);
        self::assertSame(['status' => 'archived'], $request->filters);
        self::assertTrue($request->isFiltered());
    }

    #[DataProvider('unknownSortKeys')]
    public function testAnUnknownSortKeyFallsBackToTheDefaultInsteadOfReachingTheOrderBy(mixed $sort): void
    {
        $request = ListRequest::fromQueryParams(['sort' => $sort], self::schema());

        self::assertSame('name', $request->sort);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unknownSortKeys(): iterable
    {
        yield 'a column that exists but is not declared' => ['notes'];
        yield 'an injection attempt' => ['t.name; DROP TABLE thirdparty'];
        yield 'a DQL expression' => ['t.name'];
        yield 'an array' => [['name']];
        yield 'null' => [null];
        yield 'the empty string' => [''];
    }

    #[DataProvider('directions')]
    public function testTheDirectionGrammar(mixed $raw, SortDirection $expected): void
    {
        $request = ListRequest::fromQueryParams(['dir' => $raw], self::schema());

        self::assertSame($expected, $request->direction);
    }

    /**
     * @return iterable<string, array{mixed, SortDirection}>
     */
    public static function directions(): iterable
    {
        yield 'asc' => ['asc', SortDirection::Ascending];
        yield 'desc' => ['desc', SortDirection::Descending];
        yield 'upper case' => ['DESC', SortDirection::Descending];
        yield 'padded' => [' desc ', SortDirection::Descending];
        yield 'a SQL keyword that is not one of the two' => ['descending', SortDirection::Ascending];
        yield 'nonsense falls back to the declared default' => ['sideways', SortDirection::Ascending];
        yield 'null' => [null, SortDirection::Ascending];
    }

    public function testAFilterValueOutsideItsDeclaredChoicesIsDropped(): void
    {
        $request = ListRequest::fromQueryParams(['status' => "' OR 1=1 --"], self::schema());

        self::assertSame([], $request->filters);
        self::assertNull($request->filterValue('status'));
    }

    public function testAQueryKeyThatIsNotADeclaredFilterIsIgnoredEntirely(): void
    {
        $request = ListRequest::fromQueryParams(['secret' => 'yes'], self::schema());

        self::assertSame([], $request->filters);
    }

    #[DataProvider('pages')]
    public function testThePageIsClampedLowButNotHigh(mixed $raw, int $expected): void
    {
        // The upper bound is the page count, which nothing knows before the
        // COUNT has run — the builder clamps it there.
        $request = ListRequest::fromQueryParams(['page' => $raw], self::schema());

        self::assertSame($expected, $request->page);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function pages(): iterable
    {
        yield 'a page' => ['4', 4];
        yield 'zero' => ['0', 1];
        yield 'negative' => ['-7', 1];
        yield 'text' => ['x', 1];
        yield 'absent' => [null, 1];
        yield 'far beyond the end, still carried' => ['9999', 9999];
    }

    public function testABlankSearchIsAbsentRatherThanEmpty(): void
    {
        // '%%' would match everything and cost a full scan to say so.
        self::assertNull(ListRequest::fromQueryParams(['q' => '   '], self::schema())->search);
        self::assertSame('acme', ListRequest::fromQueryParams(['q' => '  acme  '], self::schema())->search);
    }

    public function testTheQueryParamsOmitEveryDefaultAndPutThePageFirst(): void
    {
        $schema = self::schema();
        $request = ListRequest::fromQueryParams(['page' => '2'], $schema);

        // Byte-exact: a pagination link that grew ?sort=name&dir=asc&q= would
        // still work and would still be wrong.
        self::assertSame(['page' => '2'], $request->toQueryParams($schema));
        self::assertSame(['page'], array_keys($request->toQueryParams($schema)));
    }

    public function testTheFirstPageDropsThePageParameterAltogether(): void
    {
        $schema = self::schema();

        self::assertSame([], ListRequest::fromQueryParams([], $schema)->toQueryParams($schema));
    }

    public function testTheQueryParamsRoundTrip(): void
    {
        $schema = self::schema();
        $params = ['page' => '3', 'q' => 'acme', 'sort' => 'code', 'dir' => 'desc', 'status' => 'archived'];

        $once = ListRequest::fromQueryParams($params, $schema);
        $twice = ListRequest::fromQueryParams($once->toQueryParams($schema), $schema);

        self::assertEquals($once, $twice);
        self::assertSame(['page', 'q', 'sort', 'dir', 'status'], array_keys($once->toQueryParams($schema)));
    }

    public function testADirectionThatIsNotTheDefaultSurvivesADefaultSortKey(): void
    {
        $schema = self::schema();
        $request = ListRequest::fromQueryParams(['dir' => 'desc'], $schema);

        // sort is omitted (it IS the default) but dir must not be, or the
        // link would silently flip back on the next click.
        self::assertSame(['dir' => 'desc'], $request->toQueryParams($schema));
    }

    public function testClickingANewColumnStartsAscendingOnPageOne(): void
    {
        $schema = self::schema();
        $request = ListRequest::fromQueryParams(['page' => '5', 'sort' => 'name', 'dir' => 'desc'], $schema);

        $sorted = $request->sortedBy('code', $schema);

        self::assertSame('code', $sorted->sort);
        self::assertSame(SortDirection::Ascending, $sorted->direction);
        // Page 5 of the old order names different rows in the new one.
        self::assertSame(1, $sorted->page);
    }

    public function testClickingTheCurrentColumnFlipsIt(): void
    {
        $schema = self::schema();
        $request = ListRequest::fromQueryParams(['sort' => 'code'], $schema);

        self::assertSame(SortDirection::Descending, $request->sortedBy('code', $schema)->direction);
    }

    public function testSortingKeepsTheSearchAndTheFilters(): void
    {
        $schema = self::schema();
        $request = ListRequest::fromQueryParams(['q' => 'acme', 'status' => 'active'], $schema);

        $sorted = $request->sortedBy('code', $schema);

        self::assertSame('acme', $sorted->search);
        self::assertSame(['status' => 'active'], $sorted->filters);
    }

    public function testSortingByAnUndeclaredColumnChangesNothing(): void
    {
        $schema = self::schema();
        $request = ListRequest::fromQueryParams([], $schema);

        self::assertEquals($request, $request->sortedBy('notes', $schema));
    }

    public function testPagingKeepsEverythingElse(): void
    {
        $schema = self::schema();
        $request = ListRequest::fromQueryParams(['q' => 'acme', 'sort' => 'code', 'status' => 'active'], $schema);

        $next = $request->withPage(2);

        self::assertSame(2, $next->page);
        self::assertSame('acme', $next->search);
        self::assertSame('code', $next->sort);
        self::assertSame(['status' => 'active'], $next->filters);
    }

    private static function schema(): ListSchema
    {
        return new ListSchema(
            alias: 't',
            sorts: ['name' => 't.name', 'code' => 't.code'],
            tiebreaker: 't.id',
            defaultSort: 'name',
            defaultDirection: SortDirection::Ascending,
            perPage: 25,
            search: "t.name LIKE :q ESCAPE '!'",
            filters: [
                'status' => new ListFilter('status', 'x.status', 'x.status', [
                    'active' => 't.active = true',
                    'archived' => 't.active = false',
                ]),
            ],
        );
    }
}
