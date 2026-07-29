<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Scope;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Appends "company_id IN (...)" to every query touching a scoped entity.
 *
 * This is the first line of defence, not the only one. Doctrine applies filters
 * when generating SQL, so anything served from the identity map without a query
 * bypasses it entirely — see CompanyScopeListener, which covers that gap.
 */
final class CompanyScopeFilter extends SQLFilter
{
    public const NAME = 'company_scope';

    public const PARAMETER = 'companies';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->reflClass?->implementsInterface(CompanyScoped::class) !== true) {
            return '';
        }

        return sprintf('%s.%s IN (%s)', $targetTableAlias, CompanyScoped::COLUMN, $this->getParameterList(self::PARAMETER));
    }
}
