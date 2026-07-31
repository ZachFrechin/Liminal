<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Http\Api;

use Liminal\Module\Invoice\Entity\Invoice;
use Liminal\Module\Invoice\Entity\InvoiceLine;

/**
 * The one JSON shape of an invoice, shared by the list and the detail.
 * Money travels as the DECIMAL strings the entity holds — no float ever
 * touches an amount, the API included. Dates are their DATE columns, Y-m-d.
 */
final readonly class InvoicePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Invoice $invoice): array
    {
        return [
            'id' => $invoice->getId(),
            'thirdparty_id' => $invoice->getThirdpartyId(),
            'status' => $invoice->getStatus(),
            'number' => $invoice->getNumber(),
            'issued_on' => $invoice->getIssuedOn()->format('Y-m-d'),
            'due_on' => $invoice->getDueOn()?->format('Y-m-d'),
            'total_excl' => $invoice->getTotalExcl(),
            'total_tax' => $invoice->getTotalTax(),
            'total_incl' => $invoice->getTotalIncl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function line(InvoiceLine $line): array
    {
        return [
            'id' => $line->getId(),
            'position' => $line->getPosition(),
            'label' => $line->getLabel(),
            'quantity' => $line->getQuantity(),
            'unit_price' => $line->getUnitPrice(),
            'vat_rate' => $line->getVatRate(),
        ];
    }
}
