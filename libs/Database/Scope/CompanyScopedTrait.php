<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Scope;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use LogicException;

/**
 * Supplies the company_id column and accessors required by CompanyScoped.
 *
 * Every scoped table should carry an index leading with company_id: the filter adds
 * "company_id IN (...)" to every single query, so without it each list query
 * degrades to a scan.
 */
trait CompanyScopedTrait
{
    #[ORM\Column(name: CompanyScoped::COLUMN, type: Types::INTEGER)]
    private ?int $companyId = null;

    public function getCompanyId(): ?int
    {
        return $this->companyId;
    }

    /**
     * Creation-time targeting of another accessible company; the persist-time
     * listener still validates the target. Deliberately absent from the
     * CompanyScoped interface so the contract stays read-only.
     *
     * @throws LogicException when a different company id was already assigned
     */
    public function assignCompanyId(int $companyId): void
    {
        if ($this->companyId !== null && $this->companyId !== $companyId) {
            throw new LogicException(sprintf(
                'company_id is write-once (is %d, refusing %d): moving rows between companies is not an ORM operation.',
                $this->companyId,
                $companyId,
            ));
        }

        $this->companyId = $companyId;
    }
}
