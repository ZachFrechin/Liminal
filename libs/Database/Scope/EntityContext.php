<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Scope;

use InvalidArgumentException;

/**
 * The company currently being worked in, plus every company the actor may reach.
 *
 * Switching company is not a plain setter: Doctrine's SQL filters do not affect
 * entities already in the identity map, so anything loaded under the previous
 * scope must be evicted. Callers therefore cannot mutate the scope directly —
 * switchTo() notifies its listeners and EntityManagerFactory attaches one that
 * clears the EntityManager and re-applies the filter parameters. Making that
 * impossible to forget is the whole point of routing it through here.
 */
final class EntityContext
{
    /** @var list<int> */
    private array $accessibleIds;

    /** @var list<callable(self): void> */
    private array $listeners = [];

    public function __construct(private int $currentId, int ...$accessibleIds)
    {
        $this->accessibleIds = $this->normalise($currentId, $accessibleIds);
    }

    public function currentId(): int
    {
        return $this->currentId;
    }

    /** @return list<int> */
    public function accessibleIds(): array
    {
        return $this->accessibleIds;
    }

    public function canAccess(?int $entityId): bool
    {
        return $entityId !== null && in_array($entityId, $this->accessibleIds, true);
    }

    /**
     * @param callable(self): void $listener
     */
    public function onSwitch(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function switchTo(int $currentId, int ...$accessibleIds): void
    {
        $this->currentId = $currentId;
        $this->accessibleIds = $this->normalise($currentId, $accessibleIds);

        foreach ($this->listeners as $listener) {
            $listener($this);
        }
    }

    /**
     * @param list<int> $accessibleIds
     *
     * @return list<int>
     */
    private function normalise(int $currentId, array $accessibleIds): array
    {
        if ($currentId < 1) {
            throw new InvalidArgumentException(sprintf('Entity id must be positive, got %d.', $currentId));
        }

        // The current company is always reachable, even if the caller omitted it.
        $ids = array_values(array_unique([$currentId, ...$accessibleIds]));
        sort($ids);

        return $ids;
    }
}
