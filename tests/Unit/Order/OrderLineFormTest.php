<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Order;

use Liminal\Module\Order\Http\OrderLineForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The order line's twin of the invoice characterisation. Same caps, same
 * digit-count predicate, same reason for existing: an order becomes an
 * invoice by copy, so a boundary that drifted on one side would fabricate a
 * line the other side would have refused.
 */
#[CoversClass(OrderLineForm::class)]
final class OrderLineFormTest extends TestCase
{
    public function testAnEmptyBodyFailsEveryFieldInDeclarationOrder(): void
    {
        $form = OrderLineForm::fromBody([]);

        self::assertSame([
            'order.form.label_required',
            'order.form.quantity_invalid',
            'order.form.unit_price_invalid',
            'order.form.vat_rate_invalid',
        ], $form->errors);
        self::assertSame('order.form.label_required', $form->firstError());
    }

    public function testAValidLineReportsNothing(): void
    {
        $form = OrderLineForm::fromBody([
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
        $form = OrderLineForm::fromBody([
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
        $form = OrderLineForm::fromBody([
            'label' => 'L',
            'quantity' => 'x',
            'unit_price' => '10',
            'vat_rate' => '20',
        ]);

        self::assertSame(['order.form.quantity_invalid'], $form->errors);
        self::assertSame('', $form->quantity);
        self::assertSame('10', $form->unitPrice);
    }

    public function testTheLabelBoundaryIsTwoHundredAndFiftyFiveCharacters(): void
    {
        $body = ['quantity' => '1', 'unit_price' => '1', 'vat_rate' => '1'];

        self::assertSame([], OrderLineForm::fromBody(['label' => str_repeat('é', 255)] + $body)->errors);
        self::assertSame(
            ['order.form.label_required'],
            OrderLineForm::fromBody(['label' => str_repeat('é', 256)] + $body)->errors,
        );
        self::assertSame('Consulting', OrderLineForm::fromBody(['label' => '  Consulting  '] + $body)->label);
    }

    public function testARateAboveOneHundredIsRefusedOnceAndOnlyOnce(): void
    {
        $form = OrderLineForm::fromBody([
            'label' => 'L',
            'quantity' => '1',
            'unit_price' => '1',
            'vat_rate' => '999',
        ]);

        self::assertSame(['order.form.vat_rate_invalid'], $form->errors);
    }

    /**
     * @param non-empty-string $quantity
     */
    #[DataProvider('quantities')]
    public function testTheQuantityCap(string $quantity, bool $accepted): void
    {
        $form = OrderLineForm::fromBody([
            'label' => 'L',
            'quantity' => $quantity,
            'unit_price' => '1',
            'vat_rate' => '1',
        ]);

        self::assertSame($accepted ? [] : ['order.form.quantity_invalid'], $form->errors);
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
        $form = OrderLineForm::fromBody([
            'label' => 'L',
            'quantity' => '1',
            'unit_price' => $unitPrice,
            'vat_rate' => '1',
        ]);

        self::assertSame($accepted ? [] : ['order.form.unit_price_invalid'], $form->errors);
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
        $form = OrderLineForm::fromBody([
            'label' => 'L',
            'quantity' => '1',
            'unit_price' => '1',
            'vat_rate' => $rate,
        ]);

        self::assertSame($accepted ? [] : ['order.form.vat_rate_invalid'], $form->errors);
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
        yield 'just above one hundred' => ['100.01', false];
        yield 'just below one hundred' => ['99.99', true];
        yield 'a fourth integer digit' => ['1000', false];
    }
}
