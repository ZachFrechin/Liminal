<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Totals;

use Liminal\Lib\Database\Money\DocumentTotals;
use Liminal\Lib\Database\Money\TotalsCalculator;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Hook\Hooks;
use Liminal\Module\Invoice\Entity\Invoice;
use Liminal\Module\Invoice\Entity\InvoiceLine;
use Liminal\Module\Invoice\Exception\InvoiceModuleException;

/**
 * The production dispatch site of invoice.total.compute — the name the
 * phase-0 table promised. The calculator owns the base value, listeners
 * transform it (a discount module, a rounding rule, a surcharge), and being
 * the declarer, this service validates the shape that comes back: hook
 * exceptions propagate, and so does a listener returning something an
 * invoice cannot store.
 */
final readonly class InvoiceTotalsService
{
    public function __construct(
        private TotalsCalculator $calculator,
        private Hooks $hooks,
        private CompanyContext $context,
    ) {}

    /**
     * @param list<InvoiceLine> $lines
     *
     * @throws InvoiceModuleException when the invoice was never flushed, or a listener returned a foreign shape
     */
    public function totalsFor(Invoice $invoice, array $lines): DocumentTotals
    {
        $id = $invoice->getId() ?? throw InvoiceModuleException::unpersistedInvoice();

        $totals = $this->hooks->filter('invoice.total.compute', $this->calculator->compute($lines), [
            'invoice_id' => $id,
            'company_id' => $this->context->currentId(),
        ]);

        return $totals instanceof DocumentTotals
            ? $totals
            : throw InvoiceModuleException::foreignTotals(get_debug_type($totals));
    }
}
