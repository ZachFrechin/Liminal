<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Database\Query;

use Liminal\Lib\Database\Exception\DatabaseException;
use Liminal\Lib\Database\Query\LikeNeedle;
use Liminal\Lib\Database\Query\ListFilter;
use Liminal\Lib\Database\Query\ListSchema;
use Liminal\Lib\Database\Query\SortDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The declaring half: what a list writes down about itself, and the
 * contradictions it refuses at construction rather than at query time.
 */
#[CoversClass(ListSchema::class)]
#[CoversClass(ListFilter::class)]
#[CoversClass(LikeNeedle::class)]
final class ListSchemaTest extends TestCase
{
    public function testTheOrderByCarriesTheTiebreakerInTheSameDirection(): void
    {
        // Same direction on purpose: a descending page whose tiebreaker
        // ascends reads its last row out of order.
        self::assertSame('t.name DESC, t.id DESC', self::schema()->orderByClause('name', SortDirection::Descending));
        self::assertSame('t.name ASC, t.id ASC', self::schema()->orderByClause('name', SortDirection::Ascending));
    }

    public function testAListSortedByItsOwnTiebreakerNamesItOnce(): void
    {
        $schema = new ListSchema(
            alias: 'i',
            sorts: ['recent' => 'i.id', 'number' => 'i.number'],
            tiebreaker: 'i.id',
            defaultSort: 'recent',
            defaultDirection: SortDirection::Descending,
            perPage: 25,
        );

        self::assertSame('i.id DESC', $schema->orderByClause('recent', SortDirection::Descending));
        self::assertSame('i.number ASC, i.id ASC', $schema->orderByClause('number', SortDirection::Ascending));
    }

    public function testAnUnknownKeyOrdersByTheDefaultRatherThanByNothing(): void
    {
        // Belt and braces: ListRequest already refuses it, but the ordering
        // is the one place a bad key would become SQL.
        self::assertSame('t.name ASC, t.id ASC', self::schema()->orderByClause('nope', SortDirection::Ascending));
    }

    public function testTheOrderingComesOutAsPairsBecauseAQueryBuilderTakesThemApart(): void
    {
        // Handed a whole clause, Doctrine's orderBy() appends its own ASC and
        // "i.id DESC" becomes "i.id DESC ASC" — a syntax error at run time,
        // in a query no unit test would have built.
        self::assertSame(
            [['t.name', 'DESC'], ['t.id', 'DESC']],
            self::schema()->ordering('name', SortDirection::Descending),
        );
    }

    public function testTheSchemaRefusesADefaultItDoesNotDeclare(): void
    {
        $this->expectException(DatabaseException::class);

        new ListSchema(
            alias: 't',
            sorts: ['name' => 't.name'],
            tiebreaker: 't.id',
            defaultSort: 'code',
            defaultDirection: SortDirection::Ascending,
            perPage: 25,
        );
    }

    public function testTheSchemaRefusesAPageThatHoldsNothing(): void
    {
        $this->expectException(DatabaseException::class);

        new ListSchema(
            alias: 't',
            sorts: ['name' => 't.name'],
            tiebreaker: 't.id',
            defaultSort: 'name',
            defaultDirection: SortDirection::Ascending,
            perPage: 0,
        );
    }

    public function testTheSchemaRefusesAFilterFiledUnderAnotherKey(): void
    {
        // The request reads filters by their own key; a mismatch would make
        // the filter unreachable and silent.
        $this->expectException(DatabaseException::class);

        new ListSchema(
            alias: 't',
            sorts: ['name' => 't.name'],
            tiebreaker: 't.id',
            defaultSort: 'name',
            defaultDirection: SortDirection::Ascending,
            perPage: 25,
            filters: ['state' => new ListFilter('status', 'x', 'x', ['active' => 't.active = true'])],
        );
    }

    public function testAFilterRefusesAChoiceThatNarrowsNothing(): void
    {
        $this->expectException(DatabaseException::class);

        new ListFilter('status', 'x', 'x', ['active' => '  ']);
    }

    public function testTheCountDistinguishesOnlyWhenItIsDeclaredTo(): void
    {
        self::assertSame('COUNT(t.id)', self::schema()->countExpression());

        $joined = new ListSchema(
            alias: 'u',
            sorts: ['email' => 'u.email'],
            tiebreaker: 'u.id',
            defaultSort: 'email',
            defaultDirection: SortDirection::Ascending,
            perPage: 25,
            countDistinct: true,
        );

        self::assertSame('COUNT(DISTINCT u.id)', $joined->countExpression());
    }

    public function testAFilterKnowsItsChoicesAndTheirCatalogueKeys(): void
    {
        $filter = new ListFilter('kind', 'thirdparty.filter.kind', 'thirdparty.kind', [
            'customer' => 't.customer = true',
            'supplier' => 't.supplier = true',
        ]);

        self::assertTrue($filter->accepts('customer'));
        self::assertFalse($filter->accepts('prospect'));
        self::assertSame(['customer', 'supplier'], $filter->values());
        self::assertSame('thirdparty.kind.customer', $filter->labelFor('customer'));
    }

    #[DataProvider('needles')]
    public function testTheNeedleEscapesTheWildcardsABoundParameterDoesNot(?string $raw, ?string $expected): void
    {
        self::assertSame($expected, LikeNeedle::wrap($raw));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function needles(): iterable
    {
        yield 'ordinary text' => ['acme', '%acme%'];
        yield 'trimmed' => ['  acme  ', '%acme%'];
        yield 'blank is absent, not a match-all' => ['   ', null];
        yield 'null is absent' => [null, null];
        // The three characters that mean something to LIKE. Escaping the
        // escape first is why '!' does not double twice.
        yield 'a percent is literal' => ['100%', '%100!%%'];
        yield 'an underscore is literal' => ['a_b', '%a!_b%'];
        yield 'the escape character is literal' => ['a!b', '%a!!b%'];
        yield 'a backslash is ordinary text' => ['a\\b', '%a\\b%'];
    }

    public function testTheNeedleIsBoundedInCharactersNotBytes(): void
    {
        $needle = LikeNeedle::wrap(str_repeat('é', LikeNeedle::MAX_QUERY_LENGTH + 50));

        self::assertNotNull($needle);
        // Two wrapping % plus the cap.
        self::assertSame(LikeNeedle::MAX_QUERY_LENGTH + 2, mb_strlen($needle));
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
        );
    }
}
