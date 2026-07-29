<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Scope;

use Doctrine\ORM\Mapping as ORM;

/**
 * Supplies the company_id column and accessors required by CompanyScoped.
 *
 * Every scoped table should carry an index leading with company_id: the filter adds
 * "company_id IN (...)" to every single query, so without it each list query
 * degrades to a scan.
 */
trait CompanyScopedTrait
{
    #[ORM\Column(name: 'company_id', type: 'integer')]
    private ?int $companyId = null;

    public function getCompanyId(): ?int
    {
        return $this->companyId;
    }

    public function setCompanyId(int $companyId): void
    {
        $this->companyId = $companyId;
    }
}
