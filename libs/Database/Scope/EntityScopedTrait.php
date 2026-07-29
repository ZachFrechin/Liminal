<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Scope;

use Doctrine\ORM\Mapping as ORM;

/**
 * Supplies the entity_id column and accessors required by EntityScoped.
 *
 * Every scoped table should carry an index leading with entity_id: the filter adds
 * "entity_id IN (...)" to every single query, so without it each list query
 * degrades to a scan.
 */
trait EntityScopedTrait
{
    #[ORM\Column(name: 'entity_id', type: 'integer')]
    private ?int $entityId = null;

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(int $entityId): void
    {
        $this->entityId = $entityId;
    }
}
