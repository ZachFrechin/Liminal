<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Scope;

/**
 * Marks an entity as belonging to exactly one company (a "core_company" row).
 *
 * Implementing this is what opts a table into automatic scoping: the SQL filter,
 * the prePersist assignment and the load-time guard all key off this interface.
 * Use CompanyScopedTrait for the mapping and the accessors.
 *
 * The company id is write-once by contract, which is why this interface exposes
 * no setter: it is stamped from the current scope at persist time (or targeted
 * explicitly before persist via CompanyScopedTrait::assignCompanyId()), and the
 * CompanyScopeListener onFlush gate refuses any later change. Moving rows
 * between companies is an administrative data migration, not an ORM operation.
 */
interface CompanyScoped
{
    /**
     * The scoping column every scoped table carries. Single source of truth for
     * the SQL filter, the mapping trait and the flush-time guard.
     */
    public const string COLUMN = 'company_id';

    public function getCompanyId(): ?int;
}
