<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Scope;

/**
 * Marks an entity as belonging to exactly one company (a "core_company" row).
 *
 * Implementing this is what opts a table into automatic scoping: the SQL filter,
 * the prePersist assignment and the load-time guard all key off this interface.
 * Use CompanyScopedTrait for the mapping and the accessors.
 */
interface CompanyScoped
{
    public function getCompanyId(): ?int;

    public function setCompanyId(int $companyId): void;
}
