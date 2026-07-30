<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Totals;

use Liminal\Module\Invoice\Exception\InvoiceModuleException;
use Liminal\Module\Invoice\Money\Cents;

/**
 * What an invoice adds up to, in integer cents: the value that travels
 * through invoice.total.compute. Listeners receive one and return one; the
 * dispatch site validates the instance, this constructor validates the
 * range — DECIMAL(14,2) is the storage ceiling, and the form caps keep
 * legitimate values far below it, so an overflow here is a listener bug.
 */
final readonly class InvoiceTotals
{
    /** 999 999 999 999.99 — what DECIMAL(14,2) can hold. */
    private const int MAX_CENTS = 99_999_999_999_999;

    /**
     * @param array<string, int> $vatByRate cents of VAT per 2-decimal rate string, e.g. '20.00' => 350
     *
     * @throws InvoiceModuleException when a total exceeds the storage ceiling
     */
    public function __construct(
        public int $totalExcl,
        public array $vatByRate,
        public int $totalIncl,
    ) {
        foreach (['excluding-tax' => $totalExcl, 'including-tax' => $totalIncl, 'tax' => $this->totalTax()] as $which => $cents) {
            if ($cents < 0 || $cents > self::MAX_CENTS) {
                throw InvoiceModuleException::totalsOutOfRange($which, $cents);
            }
        }
    }

    public function totalTax(): int
    {
        return array_sum($this->vatByRate);
    }

    public function totalExclAsDecimal(): string
    {
        return Cents::toDecimal($this->totalExcl);
    }

    public function totalTaxAsDecimal(): string
    {
        return Cents::toDecimal($this->totalTax());
    }

    public function totalInclAsDecimal(): string
    {
        return Cents::toDecimal($this->totalIncl);
    }
}
