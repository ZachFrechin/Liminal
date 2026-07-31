<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * Wiring failures inside the order module — content problems (an invalid
 * form value, a refused validation or conversion) travel as flashes, never
 * as exceptions.
 */
final class OrderModuleException extends LogicException implements LiminalException
{
    public static function sessionMissing(): self
    {
        return new self('The session middleware did not run for this request.');
    }

    public static function notDraft(int $id): self
    {
        return new self(sprintf(
            'Order %d left the draft state and is immutable: the handler must refuse before the entity has to.',
            $id,
        ));
    }

    public static function notValidated(int $id): self
    {
        return new self(sprintf(
            'Order %d is not validated: only a validated order can be invoiced, and the handler must refuse first.',
            $id,
        ));
    }

    public static function foreignTotals(string $type): self
    {
        return new self(sprintf(
            'An order.total.compute listener returned %s instead of DocumentTotals — the declarer validates the shape, and this one is wiring.',
            $type,
        ));
    }

    public static function unpersistedOrder(): self
    {
        return new self('The order has no id yet: compute totals after the first flush, not before.');
    }
}
