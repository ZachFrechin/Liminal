<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Http\Api;

use DateTimeInterface;
use Liminal\Module\Thirdparty\Entity\Thirdparty;

/**
 * The one JSON shape of a thirdparty, shared by the list and the detail so
 * the two can never drift. Booleans are booleans, dates are ISO 8601 —
 * everything else travels as the entity holds it.
 */
final readonly class ThirdpartyPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Thirdparty $thirdparty): array
    {
        return [
            'id' => $thirdparty->getId(),
            'code' => $thirdparty->getCode(),
            'name' => $thirdparty->getName(),
            'alias' => $thirdparty->getAlias(),
            'customer' => $thirdparty->isCustomer(),
            'supplier' => $thirdparty->isSupplier(),
            'active' => $thirdparty->isActive(),
            'email' => $thirdparty->getEmail(),
            'phone' => $thirdparty->getPhone(),
            'address' => $thirdparty->getAddress(),
            'zip' => $thirdparty->getZip(),
            'town' => $thirdparty->getTown(),
            'country_code' => $thirdparty->getCountryCode(),
            'vat_number' => $thirdparty->getVatNumber(),
            'notes' => $thirdparty->getNotes(),
            'created_at' => $thirdparty->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $thirdparty->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
