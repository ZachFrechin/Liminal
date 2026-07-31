<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Invoice;

use Liminal\Module\Invoice\Http\InvoiceForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation: every assertion here was MEASURED against the current
 * parser, not desired. The list layer is about to be hoisted into a shared
 * lib, and these tests exist so that any behaviour the rewrite changes
 * changes VISIBLY — a red test is the whole point, not an accident.
 *
 * Two measured facts are defects rather than intentions, marked below: an
 * out-of-range date silently overflows into the next month, and scientific
 * notation reaches the id cast. Neither is fixed here; fixing them under a
 * "refactor" commit is exactly the silent change this file forbids.
 */
#[CoversClass(InvoiceForm::class)]
final class InvoiceFormTest extends TestCase
{
    public function testAnEmptyBodyFailsOnTheTwoRequiredFieldsInDeclarationOrder(): void
    {
        $form = InvoiceForm::fromBody([]);

        self::assertSame(
            ['invoice.form.thirdparty_required', 'invoice.form.issued_on_invalid'],
            $form->errors,
        );
        self::assertSame('invoice.form.thirdparty_required', $form->firstError());
    }

    public function testEveryFieldWrongAtOnceReportsThreeErrorsInFieldOrder(): void
    {
        $form = InvoiceForm::fromBody([
            'thirdparty_id' => 'x',
            'issued_on' => 'x',
            'due_on' => 'y',
        ]);

        self::assertSame([
            'invoice.form.thirdparty_required',
            'invoice.form.issued_on_invalid',
            'invoice.form.due_on_invalid',
        ], $form->errors);
    }

    public function testAValidFormReportsNothingAndKeepsTheParsedDates(): void
    {
        $form = InvoiceForm::fromBody([
            'thirdparty_id' => '7',
            'issued_on' => '2026-05-10',
            'due_on' => '2026-06-09',
        ]);

        self::assertSame([], $form->errors);
        self::assertNull($form->firstError());
        self::assertSame(7, $form->thirdpartyId);
        self::assertSame('2026-05-10', $form->issuedOn?->format('Y-m-d'));
        self::assertSame('2026-06-09', $form->dueOn?->format('Y-m-d'));
    }

    public function testTheParsedDatesCarryNoTimeOfDay(): void
    {
        // '!' resets the unspecified fields — without it the date would carry
        // the current clock, and two invoices issued the same day would
        // compare unequal.
        $form = InvoiceForm::fromBody(['thirdparty_id' => '1', 'issued_on' => '2026-05-10']);

        self::assertSame('2026-05-10 00:00:00', $form->issuedOn?->format('Y-m-d H:i:s'));
    }

    public function testAnAbsentDueDateIsSimplyAbsent(): void
    {
        $form = InvoiceForm::fromBody(['thirdparty_id' => '1', 'issued_on' => '2026-05-10']);

        self::assertSame([], $form->errors);
        self::assertNull($form->dueOn);
    }

    public function testABlankDueDateIsAbsentRatherThanInvalid(): void
    {
        // The date input posts an empty string when the user clears it; that
        // is a removal, not a typo.
        $form = InvoiceForm::fromBody([
            'thirdparty_id' => '1',
            'issued_on' => '2026-05-10',
            'due_on' => '   ',
        ]);

        self::assertSame([], $form->errors);
        self::assertNull($form->dueOn);
    }

    public function testADueDateBeforeTheIssueDateIsRefused(): void
    {
        $form = InvoiceForm::fromBody([
            'thirdparty_id' => '1',
            'issued_on' => '2026-05-10',
            'due_on' => '2026-05-09',
        ]);

        self::assertSame(['invoice.form.due_before_issue'], $form->errors);
    }

    public function testADueDateEqualToTheIssueDateIsAccepted(): void
    {
        $form = InvoiceForm::fromBody([
            'thirdparty_id' => '1',
            'issued_on' => '2026-05-10',
            'due_on' => '2026-05-10',
        ]);

        self::assertSame([], $form->errors);
    }

    public function testTheOrderComparisonIsSkippedWhenTheIssueDateIsUnusable(): void
    {
        // Both dates broken: the parser reports each field once and never
        // compares null against anything.
        $form = InvoiceForm::fromBody([
            'thirdparty_id' => '1',
            'issued_on' => 'x',
            'due_on' => 'y',
        ]);

        self::assertSame([
            'invoice.form.issued_on_invalid',
            'invoice.form.due_on_invalid',
        ], $form->errors);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    #[DataProvider('thirdpartyIdentifiers')]
    public function testTheThirdpartyIdentifierCoercion(array $body, int $expectedId, bool $expectedError): void
    {
        $form = InvoiceForm::fromBody($body + ['issued_on' => '2026-05-10']);

        self::assertSame($expectedId, $form->thirdpartyId);
        self::assertSame(
            $expectedError ? ['invoice.form.thirdparty_required'] : [],
            $form->errors,
        );
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
        // MEASURED DEFECT: is_numeric() accepts scientific notation, so a
        // select tampered to "1e3" resolves to id 1000. Harmless today (the
        // repository narrows to the company and the id simply misses), but
        // it is a coercion nobody chose.
        yield 'scientific notation reaches the cast' => [['thirdparty_id' => '1e3'], 1000, false];
        yield 'hexadecimal does not' => [['thirdparty_id' => '0x1A'], 0, true];
        yield 'zero is not an identifier' => [['thirdparty_id' => '0'], 0, true];
        yield 'a negative is not an identifier' => [['thirdparty_id' => '-2'], -2, true];
        yield 'text' => [['thirdparty_id' => 'x'], 0, true];
        yield 'a boolean' => [['thirdparty_id' => true], 0, true];
        yield 'null' => [['thirdparty_id' => null], 0, true];
        yield 'an array' => [['thirdparty_id' => ['a']], 0, true];
        yield 'the field never posted' => [[], 0, true];
    }

    /**
     * @param non-empty-string $raw
     */
    #[DataProvider('dateStrings')]
    public function testTheDateGrammar(string $raw, ?string $expected): void
    {
        $form = InvoiceForm::fromBody(['thirdparty_id' => '1', 'issued_on' => $raw]);

        self::assertSame($expected, $form->issuedOn?->format('Y-m-d'));
        self::assertSame(
            $expected === null ? ['invoice.form.issued_on_invalid'] : [],
            $form->errors,
        );
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
        yield 'nonsense' => ['x', null];
        yield 'the empty string' => [' ', null];
        // MEASURED DEFECT: createFromFormat overflows instead of refusing, so
        // a hand-typed 30 February silently becomes 2 March and a 13th month
        // silently becomes next January. The user is never told. Pinned so
        // that the shared date reader either keeps this or changes it out
        // loud — never by accident.
        yield 'an impossible day overflows into the next month' => ['2026-02-30', '2026-03-02'];
        yield 'an impossible month overflows into the next year' => ['2026-13-01', '2027-01-01'];
    }
}
