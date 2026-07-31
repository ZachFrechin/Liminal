<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Http\Api;

use DateTimeInterface;
use Liminal\Module\Order\Entity\Order;
use Liminal\Module\Order\Entity\OrderLine;

/**
 * The one JSON shape of an order, shared by the list and the detail. The
 * invoice payload plus what the third state adds: the wanted date, and —
 * once converted — the pointer to the invoice it became and when.
 */
final readonly class OrderPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Order $order): array
    {
        return [
            'id' => $order->getId(),
            'thirdparty_id' => $order->getThirdpartyId(),
            'status' => $order->getStatus(),
            'number' => $order->getNumber(),
            'issued_on' => $order->getIssuedOn()->format('Y-m-d'),
            'wanted_on' => $order->getWantedOn()?->format('Y-m-d'),
            'invoice_id' => $order->getInvoiceId(),
            'invoiced_at' => $order->getInvoicedAt()?->format(DateTimeInterface::ATOM),
            'total_excl' => $order->getTotalExcl(),
            'total_tax' => $order->getTotalTax(),
            'total_incl' => $order->getTotalIncl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function line(OrderLine $line): array
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
