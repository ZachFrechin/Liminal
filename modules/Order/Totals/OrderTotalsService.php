<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Totals;

use Liminal\Lib\Database\Money\DocumentTotals;
use Liminal\Lib\Database\Money\TotalsCalculator;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Hook\Hooks;
use Liminal\Module\Order\Entity\Order;
use Liminal\Module\Order\Entity\OrderLine;
use Liminal\Module\Order\Exception\OrderModuleException;

/**
 * The dispatch site of order.total.compute — the order's own hook, distinct
 * from the invoice's on purpose: a discount listener may treat quotes and
 * bills differently. The lib calculator owns the base value; being the
 * declarer, this service validates the shape that comes back.
 */
final readonly class OrderTotalsService
{
    public function __construct(
        private TotalsCalculator $calculator,
        private Hooks $hooks,
        private CompanyContext $context,
    ) {}

    /**
     * @param list<OrderLine> $lines
     *
     * @throws OrderModuleException when the order was never flushed, or a listener returned a foreign shape
     */
    public function totalsFor(Order $order, array $lines): DocumentTotals
    {
        $id = $order->getId() ?? throw OrderModuleException::unpersistedOrder();

        $totals = $this->hooks->filter('order.total.compute', $this->calculator->compute($lines), [
            'order_id' => $id,
            'company_id' => $this->context->currentId(),
        ]);

        return $totals instanceof DocumentTotals
            ? $totals
            : throw OrderModuleException::foreignTotals(get_debug_type($totals));
    }
}
