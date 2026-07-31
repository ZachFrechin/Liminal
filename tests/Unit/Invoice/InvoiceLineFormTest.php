<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Invoice;

use Liminal\Module\Invoice\Http\InvoiceLineForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation of the money caps — the load-bearing ones. The class
 * docblock claims quantity ≤ 999 999,99 and unit price ≤ 99 999 999,99 keep
 * the cents product inside int64; until now NOTHING held that claim to its
 * word. The caps are DIGIT COUNTS, not maxima: `\d{1,6}` refuses a seventh
 * digit, it does not compare against 999999.99. A rewrite that swapped the
 * predicate for `max: 999999.99` would pass a naive review and change the
 * boundary — these tests sit exactly on it.
 */
#[CoversClass(InvoiceLineForm::class)]
final class InvoiceLineFormTest extends TestCase
{
    public function testAnEmptyBodyFailsEveryFieldInDeclarationOrder(): void
    {
        $form = InvoiceLineForm::fromBody([]);

        self::assertSame([
            'invoice.form.label_required',
            'invoice.form.quantity_invalid',
            'invoice.form.unit_price_invalid',
            'invoice.form.vat_rate_invalid',
        ], $form->errors);
        self::assertSame('invoice.form.label_required', $form->firstError());
    }

    public function testAValidLineReportsNothing(): void
    {
        $form = InvoiceLineForm::fromBody([
            'label' => 'Consulting',
            'quantity' => '2',
            'unit_price' => '450',
            'vat_rate' => '20',
        ]);

        self::assertSame([], $form->errors);
        self::assertNull($form->firstError());
        self::assertSame('Consulting', $form->label);
        self::assertSame('2', $form->quantity);
        self::assertSame('450', $form->unitPrice);
        self::assertSame('20', $form->vatRate);
    }

    public function testTheFrenchCommaNormalisesToAPointOnAllThreeAmounts(): void
    {
        $form = InvoiceLineForm::fromBody([
            'label' => 'L',
            'quantity' => '1,5',
            'unit_price' => '2,25',
            'vat_rate' => '20,5',
        ]);

        self::assertSame([], $form->errors);
        self::assertSame('1.5', $form->quantity);
        self::assertSame('2.25', $form->unitPrice);
        self::assertSame('20.5', $form->vatRate);
    }

    public function testARefusedAmountComesBackEmptyRatherThanHalfParsed(): void
    {
        // The empty string is what makes the >100 rate check safe to skip and
        // what stops a rejected value from reaching the entity.
        $form = InvoiceLineForm::fromBody([
            'label' => 'L',
            'quantity' => 'x',
            'unit_price' => '10',
            'vat_rate' => '20',
        ]);

        self::assertSame(['invoice.form.quantity_invalid'], $form->errors);
        self::assertSame('', $form->quantity);
        self::assertSame('10', $form->unitPrice);
    }

    public function testTheLabelIsTrimmedAndBoundedInCharactersNotBytes(): void
    {
        $form = InvoiceLineForm::fromBody([
            'label' => '  Consulting  ',
            'quantity' => '1',
            'unit_price' => '1',
            'vat_rate' => '1',
        ]);

        self::assertSame('Consulting', $form->label);
        self::assertSame([], $form->errors);
    }

    public function testTheLabelBoundaryIsTwoHundredAndFiftyFiveCharacters(): void
    {
        $body = ['quantity' => '1', 'unit_price' => '1', 'vat_rate' => '1'];

        // Multibyte on purpose: 255 accented characters are 510 bytes. A
        // strlen() would refuse this and the column would still take it.
        self::assertSame([], InvoiceLineForm::fromBody(['label' => str_repeat('é', 255)] + $body)->errors);
        self::assertSame(
            ['invoice.form.label_required'],
            InvoiceLineForm::fromBody(['label' => str_repeat('é', 256)] + $body)->errors,
        );
    }

    public function testARateAboveOneHundredIsRefusedOnceAndOnlyOnce(): void
    {
        // 999 matches the three-digit pattern, so the >100 comparison would
        // fire too if the pattern failure did not blank the value first. One
        // field, one error.
        $form = InvoiceLineForm::fromBody([
            'label' => 'L',
            'quantity' => '1',
            'unit_price' => '1',
            'vat_rate' => '999',
        ]);

        self::assertSame(['invoice.form.vat_rate_invalid'], $form->errors);
    }

    /**
     * @param non-empty-string $quantity
     */
    #[DataProvider('quantities')]
    public function testTheQuantityCap(string $quantity, bool $accepted): void
    {
        $form = InvoiceLineForm::fromBody([
            'label' => 'L',
            'quantity' => $quantity,
            'unit_price' => '1',
            'vat_rate' => '1',
        ]);

        self::assertSame($accepted ? [] : ['invoice.form.quantity_invalid'], $form->errors);
    }

    /**
     * @return iterable<string, array{non-empty-string, bool}>
     */
    public static function quantities(): iterable
    {
        yield 'zero' => ['0', true];
        yield 'six integer digits, the ceiling' => ['999999', true];
        yield 'six digits and two decimals' => ['999999.99', true];
        yield 'a seventh integer digit' => ['9999999', false];
        yield 'a third decimal' => ['1.234', false];
        yield 'a leading sign' => ['+1', false];
        yield 'a bare decimal point' => ['.5', false];
        yield 'a trailing point' => ['1.', false];
        yield 'a thousands separator' => ['1 000', false];
        yield 'the empty string' => [' ', false];
    }

    /**
     * @param non-empty-string $unitPrice
     */
    #[DataProvider('unitPrices')]
    public function testTheUnitPriceCap(string $unitPrice, bool $accepted): void
    {
        $form = InvoiceLineForm::fromBody([
            'label' => 'L',
            'quantity' => '1',
            'unit_price' => $unitPrice,
            'vat_rate' => '1',
        ]);

        self::assertSame($accepted ? [] : ['invoice.form.unit_price_invalid'], $form->errors);
    }

    /**
     * @return iterable<string, array{non-empty-string, bool}>
     */
    public static function unitPrices(): iterable
    {
        yield 'zero' => ['0', true];
        yield 'eight integer digits, the ceiling' => ['99999999', true];
        yield 'eight digits and two decimals' => ['99999999.99', true];
        yield 'a ninth integer digit' => ['999999999', false];
        yield 'a third decimal' => ['1.234', false];
    }

    /**
     * @param non-empty-string $rate
     */
    #[DataProvider('vatRates')]
    public function testTheRateCap(string $rate, bool $accepted): void
    {
        $form = InvoiceLineForm::fromBody([
            'label' => 'L',
            'quantity' => '1',
            'unit_price' => '1',
            'vat_rate' => $rate,
        ]);

        self::assertSame($accepted ? [] : ['invoice.form.vat_rate_invalid'], $form->errors);
    }

    /**
     * @return iterable<string, array{non-empty-string, bool}>
     */
    public static function vatRates(): iterable
    {
        yield 'zero, an exempt line' => ['0', true];
        yield 'the standard French rate' => ['20', true];
        yield 'a fractional rate' => ['5.5', true];
        yield 'one hundred exactly' => ['100', true];
        // The numeric comparison, not the pattern: 100.01 has three integer
        // digits and two decimals, so it matches and is caught after.
        yield 'just above one hundred' => ['100.01', false];
        yield 'just below one hundred' => ['99.99', true];
        yield 'a fourth integer digit' => ['1000', false];
    }
}
