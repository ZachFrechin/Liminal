<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Money;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * The money layer's own wiring failures. Amount VALUES that users mistype
 * are form flashes long before they reach here — anything thrown from this
 * class means a listener or a caller broke the money contract.
 */
final class MoneyException extends LogicException implements LiminalException
{
    public static function totalsOutOfRange(string $which, int $cents): self
    {
        return new self(sprintf(
            'The %s total (%d cents) exceeds what DECIMAL(14,2) stores: the form caps should have made this unreachable.',
            $which,
            $cents,
        ));
    }
}
