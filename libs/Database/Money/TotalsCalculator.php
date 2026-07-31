<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Money;

use Liminal\Lib\Database\Money\Contract\DocumentLine;

/**
 * Adds a document up, in integer cents.
 *
 * Line total = round-half-up(quantity × unit price). VAT is rounded PER RATE
 * GROUP, not per line: sum the taxable base of each rate, then round its tax
 * once — that is the ventilation the document displays, and rounding anywhere
 * else would make the printed breakdown disagree with the total.
 *
 * Bounds: the form caps quantity at 6 integer digits and unit price at 8, so
 * the worst product of hundredths stays near 10^18 — inside int64 with a
 * ninefold margin. No float touches an amount at any step. These rounding
 * rules exist exactly ONCE, here — that is the whole point of the hoist.
 */
final readonly class TotalsCalculator
{
    /**
     * @param list<DocumentLine> $lines
     */
    public function compute(array $lines): DocumentTotals
    {
        $baseByRate = [];

        foreach ($lines as $line) {
            $quantity = Cents::fromDecimal($line->getQuantity());
            $unitPrice = Cents::fromDecimal($line->getUnitPrice());
            // Both factors carry two implied decimals; dividing one pair of
            // them out keeps the line total in cents, rounded half-up.
            $lineExcl = intdiv($quantity * $unitPrice + 50, 100);

            $rate = Cents::toDecimal(Cents::fromDecimal($line->getVatRate()));
            $baseByRate[$rate] = ($baseByRate[$rate] ?? 0) + $lineExcl;
        }

        ksort($baseByRate);

        $totalExcl = 0;
        $vatByRate = [];

        foreach ($baseByRate as $rate => $base) {
            $totalExcl += $base;
            // The rate's hundredths are basis points: 20.00 % = 2000 of them.
            $vatByRate[$rate] = intdiv($base * Cents::fromDecimal($rate) + 5_000, 10_000);
        }

        return new DocumentTotals($totalExcl, $vatByRate, $totalExcl + array_sum($vatByRate));
    }
}
