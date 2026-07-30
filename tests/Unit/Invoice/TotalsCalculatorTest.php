<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Invoice;

use Liminal\Module\Invoice\Entity\InvoiceLine;
use Liminal\Module\Invoice\Exception\InvoiceModuleException;
use Liminal\Module\Invoice\Totals\InvoiceTotals;
use Liminal\Module\Invoice\Totals\TotalsCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TotalsCalculator::class)]
#[CoversClass(InvoiceTotals::class)]
final class TotalsCalculatorTest extends TestCase
{
    private TotalsCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TotalsCalculator();
    }

    public function testASingleLineAddsUp(): void
    {
        // 2 × 100.00 at 20% — the arithmetic anyone can check on paper.
        $totals = $this->calculator->compute([$this->line('2.00', '100.00', '20.00')]);

        self::assertSame(20000, $totals->totalExcl);
        self::assertSame(['20.00' => 4000], $totals->vatByRate);
        self::assertSame(24000, $totals->totalIncl);
    }

    public function testVatRoundsPerRateGroupNotPerLine(): void
    {
        // Three lines of 0.33 at 20%: per-line VAT would round 0.066 → 0.07
        // three times (0.21); the group rounds once on 0.99 → 0.20. The
        // printed ventilation must agree with the total, so the group wins.
        $lines = [
            $this->line('1.00', '0.33', '20.00'),
            $this->line('1.00', '0.33', '20.00'),
            $this->line('1.00', '0.33', '20.00'),
        ];

        $totals = $this->calculator->compute($lines);

        self::assertSame(99, $totals->totalExcl);
        self::assertSame(['20.00' => 20], $totals->vatByRate);
        self::assertSame(119, $totals->totalIncl);
    }

    public function testRatesVentilateSeparatelyAndSortedByRate(): void
    {
        $lines = [
            $this->line('1.00', '100.00', '20.00'),
            $this->line('1.00', '50.00', '5.50'),
            $this->line('1.00', '10.00', '0.00'),
            $this->line('1.00', '40.00', '20.00'),
        ];

        $totals = $this->calculator->compute($lines);

        self::assertSame(20000, $totals->totalExcl);
        // ksort compares numeric strings numerically: ascending rates, which
        // is exactly the order a ventilation reads in.
        self::assertSame(['0.00' => 0, '5.50' => 275, '20.00' => 2800], $totals->vatByRate);
        self::assertSame(275 + 2800, $totals->totalTax());
        self::assertSame(23075, $totals->totalIncl);
    }

    public function testFractionalQuantitiesRoundHalfUpOnTheLine(): void
    {
        // 1.50 × 33.33 = 49.995 → 50.00 (half up), then 20% of 50.00 = 10.00.
        $totals = $this->calculator->compute([$this->line('1.50', '33.33', '20.00')]);

        self::assertSame(5000, $totals->totalExcl);
        self::assertSame(['20.00' => 1000], $totals->vatByRate);
    }

    public function testAnEmptyInvoiceIsAllZeros(): void
    {
        $totals = $this->calculator->compute([]);

        self::assertSame(0, $totals->totalExcl);
        self::assertSame([], $totals->vatByRate);
        self::assertSame(0, $totals->totalIncl);
        self::assertSame('0.00', $totals->totalInclAsDecimal());
    }

    public function testTotalsBeyondTheStorageCeilingThrow(): void
    {
        // A listener bug, not a form value: the caps keep real input far away.
        $this->expectException(InvoiceModuleException::class);
        $this->expectExceptionMessage('DECIMAL(14,2)');

        new InvoiceTotals(99_999_999_999_999 + 1, [], 0);
    }

    private function line(string $quantity, string $unitPrice, string $vatRate): InvoiceLine
    {
        return new InvoiceLine(1, 1, 'A line', $quantity, $unitPrice, $vatRate);
    }
}
