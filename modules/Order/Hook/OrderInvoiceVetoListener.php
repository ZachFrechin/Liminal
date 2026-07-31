<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Hook;

use Liminal\Lib\Hook\Contract\HookListener;
use Liminal\Lib\Hook\HookContext;
use Liminal\Module\Order\Repository\OrderRepository;

/**
 * Answers invoice.deletion.veto: an invoice that realises a converted order
 * must not disappear — deleting it would leave the order pointing at
 * nothing, and unlinking is a recorded gap, not a feature. The politeness
 * layer over the schema's RESTRICT belt: the flash answers before the
 * database has to.
 *
 * The count is narrowed to the current company like every repository read —
 * conversion is same-company by construction, so the narrowing costs
 * nothing and keeps the invariant uniform.
 */
final readonly class OrderInvoiceVetoListener implements HookListener
{
    public function __construct(private OrderRepository $orders) {}

    public function transform(mixed $value, HookContext $context): mixed
    {
        $reasons = is_array($value) ? $value : [];
        $invoiceId = $context->parameters['invoice_id'] ?? null;

        if (is_int($invoiceId) && $this->orders->countForInvoice($invoiceId) > 0) {
            $reasons[] = 'order.veto.invoice_referenced';
        }

        return $reasons;
    }
}
