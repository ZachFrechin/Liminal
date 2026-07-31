<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Order;

use Liminal\Module\Order\Http\OrderForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The order header's twin of the invoice characterisation — deliberately a
 * duplicate rather than a shared base. The two parsers ARE duplicates today
 * and are about to be collapsed onto a shared reader; a test that reads them
 * through one abstraction could not tell the day they diverged, which is the
 * one thing this file is for. Optional date renamed (wanted, not due) and
 * every key prefixed `order.`.
 */
#[CoversClass(OrderForm::class)]
final class OrderFormTest extends TestCase
{
    public function testAnEmptyBodyFailsOnTheTwoRequiredFieldsInDeclarationOrder(): void
    {
        $form = OrderForm::fromBody([]);

        self::assertSame(
            ['order.form.thirdparty_required', 'order.form.issued_on_invalid'],
            $form->errors,
        );
        self::assertSame('order.form.thirdparty_required', $form->firstError());
    }

    public function testEveryFieldWrongAtOnceReportsThreeErrorsInFieldOrder(): void
    {
        $form = OrderForm::fromBody([
            'thirdparty_id' => 'x',
            'issued_on' => 'x',
            'wanted_on' => 'y',
        ]);

        self::assertSame([
            'order.form.thirdparty_required',
            'order.form.issued_on_invalid',
            'order.form.wanted_on_invalid',
        ], $form->errors);
    }

    public function testAValidFormReportsNothingAndKeepsTheParsedDates(): void
    {
        $form = OrderForm::fromBody([
            'thirdparty_id' => '7',
            'issued_on' => '2026-05-10',
            'wanted_on' => '2026-06-09',
        ]);

        self::assertSame([], $form->errors);
        self::assertNull($form->firstError());
        self::assertSame(7, $form->thirdpartyId);
        self::assertSame('2026-05-10 00:00:00', $form->issuedOn?->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-09', $form->wantedOn?->format('Y-m-d'));
    }

    public function testABlankWantedDateIsAbsentRatherThanInvalid(): void
    {
        $form = OrderForm::fromBody([
            'thirdparty_id' => '1',
            'issued_on' => '2026-05-10',
            'wanted_on' => '   ',
        ]);

        self::assertSame([], $form->errors);
        self::assertNull($form->wantedOn);
    }

    public function testAWantedDateBeforeTheIssueDateIsRefused(): void
    {
        $form = OrderForm::fromBody([
            'thirdparty_id' => '1',
            'issued_on' => '2026-05-10',
            'wanted_on' => '2026-05-09',
        ]);

        self::assertSame(['order.form.wanted_before_issue'], $form->errors);
    }

    public function testAWantedDateEqualToTheIssueDateIsAccepted(): void
    {
        // Same-day delivery is an order, not a typo.
        $form = OrderForm::fromBody([
            'thirdparty_id' => '1',
            'issued_on' => '2026-05-10',
            'wanted_on' => '2026-05-10',
        ]);

        self::assertSame([], $form->errors);
    }

    public function testTheOrderComparisonIsSkippedWhenTheIssueDateIsUnusable(): void
    {
        $form = OrderForm::fromBody([
            'thirdparty_id' => '1',
            'issued_on' => 'x',
            'wanted_on' => 'y',
        ]);

        self::assertSame([
            'order.form.issued_on_invalid',
            'order.form.wanted_on_invalid',
        ], $form->errors);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    #[DataProvider('thirdpartyIdentifiers')]
    public function testTheThirdpartyIdentifierCoercion(array $body, int $expectedId, bool $expectedError): void
    {
        $form = OrderForm::fromBody($body + ['issued_on' => '2026-05-10']);

        self::assertSame($expectedId, $form->thirdpartyId);
        self::assertSame($expectedError ? ['order.form.thirdparty_required'] : [], $form->errors);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, int, bool}>
     */
    public static function thirdpartyIdentifiers(): iterable
    {
        yield 'a plain identifier' => [['thirdparty_id' => '3'], 3, false];
        yield 'an integer, as the API posts it' => [['thirdparty_id' => 7], 7, false];
        yield 'padded by the browser' => [['thirdparty_id' => ' 4 '], 4, false];
        yield 'a decimal truncates' => [['thirdparty_id' => '3.7'], 3, false];
        yield 'scientific notation reaches the cast' => [['thirdparty_id' => '1e3'], 1000, false];
        yield 'zero is not an identifier' => [['thirdparty_id' => '0'], 0, true];
        yield 'a negative is not an identifier' => [['thirdparty_id' => '-2'], -2, true];
        yield 'text' => [['thirdparty_id' => 'x'], 0, true];
        yield 'null' => [['thirdparty_id' => null], 0, true];
        yield 'the field never posted' => [[], 0, true];
    }

    /**
     * @param non-empty-string $raw
     */
    #[DataProvider('dateStrings')]
    public function testTheDateGrammar(string $raw, ?string $expected): void
    {
        $form = OrderForm::fromBody(['thirdparty_id' => '1', 'issued_on' => $raw]);

        self::assertSame($expected, $form->issuedOn?->format('Y-m-d'));
        self::assertSame($expected === null ? ['order.form.issued_on_invalid'] : [], $form->errors);
    }

    /**
     * @return iterable<string, array{non-empty-string, ?string}>
     */
    public static function dateStrings(): iterable
    {
        yield 'the input format' => ['2026-01-05', '2026-01-05'];
        yield 'single digits' => ['2026-1-5', '2026-01-05'];
        yield 'surrounding whitespace' => ['  2026-01-05  ', '2026-01-05'];
        yield 'trailing garbage' => ['2026-01-05x', null];
        yield 'a datetime' => ['2026-01-05T10:00', null];
        yield 'the empty string' => [' ', null];
        // The same measured overflow as the invoice header: pinned, not
        // endorsed. See InvoiceFormTest for the reasoning.
        yield 'an impossible day overflows into the next month' => ['2026-02-30', '2026-03-02'];
        yield 'an impossible month overflows into the next year' => ['2026-13-01', '2027-01-01'];
    }
}
