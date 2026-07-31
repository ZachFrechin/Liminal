<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Exception;

use Liminal\Exception\LiminalException;
use LogicException;

/**
 * Wiring failures inside the invoice module — content problems (an invalid
 * form value, a refused validation) travel as flashes, never as exceptions.
 */
final class InvoiceModuleException extends LogicException implements LiminalException
{
    public static function sessionMissing(): self
    {
        return new self('The session middleware did not run for this request.');
    }

    public static function notDraft(int $id): self
    {
        return new self(sprintf(
            'Invoice %d is validated and immutable: the handler must refuse before the entity has to.',
            $id,
        ));
    }

    public static function foreignTotals(string $type): self
    {
        return new self(sprintf(
            'An invoice.total.compute listener returned %s instead of DocumentTotals — the declarer validates the shape, and this one is wiring.',
            $type,
        ));
    }

    public static function malformedVeto(string $type): self
    {
        return new self(sprintf(
            'An invoice.deletion.veto listener returned %s instead of a list of catalogue keys — the declarer validates the shape, and this one is wiring.',
            $type,
        ));
    }


    public static function unpersistedInvoice(): self
    {
        return new self('The invoice has no id yet: compute totals after the first flush, not before.');
    }
}
