<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Money\Contract;

/**
 * What the totals calculator needs from a document line — three decimal
 * strings, nothing else. Module line entities (invoice, order, whatever
 * document comes next) implement this the way scoped entities implement
 * CompanyScoped: the lib owns the contract, the module owns the row.
 */
interface DocumentLine
{
    public function getQuantity(): string;

    public function getUnitPrice(): string;

    public function getVatRate(): string;
}
