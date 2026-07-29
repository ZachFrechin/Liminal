<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Scope;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Appends "entity_id IN (...)" to every query touching a scoped entity.
 *
 * This is the first line of defence, not the only one. Doctrine applies filters
 * when generating SQL, so anything served from the identity map without a query
 * bypasses it entirely — see EntityScopeGuard, which covers that gap.
 */
final class EntityScopeFilter extends SQLFilter
{
    public const NAME = 'entity_scope';

    public const PARAMETER = 'entities';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->reflClass?->implementsInterface(EntityScoped::class) !== true) {
            return '';
        }

        return sprintf('%s.entity_id IN (%s)', $targetTableAlias, $this->getParameterList(self::PARAMETER));
    }

    /**
     * @param list<int> $entityIds
     */
    public static function apply(SQLFilter $filter, array $entityIds): void
    {
        $filter->setParameterList(self::PARAMETER, $entityIds, Types::INTEGER);
    }
}
