<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Scope;

use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Liminal\Lib\Database\Scope\Exception\CrossEntityAccessException;

/**
 * Assigns and polices entity_id around the ORM lifecycle.
 *
 * prePersist stamps new scoped entities with the current company, so no caller has
 * to remember to. postLoad re-checks what actually came back — the defence in
 * depth the SQL filter cannot provide on its own, because a filter only shapes
 * generated SQL and says nothing about rows reaching the application some other
 * way (a disabled filter, a native query, a relation loaded before the scope was
 * applied).
 *
 * What this does NOT cover: find() returning an entity already in the identity
 * map fires no postLoad, since the entity was hydrated on an earlier load. That
 * case is closed at the other end — EntityContext::switchTo() clears the
 * EntityManager, so nothing hydrated under a previous scope survives into the
 * next one. Neither mechanism is sufficient alone; phase 3 adds voters on top.
 */
final readonly class EntityScopeListener
{
    public function __construct(private EntityContext $context) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof EntityScoped) {
            return;
        }

        if ($entity->getEntityId() === null) {
            $entity->setEntityId($this->context->currentId());

            return;
        }

        // An explicit id is honoured only if the actor may actually reach it.
        if (!$this->context->canAccess($entity->getEntityId())) {
            throw CrossEntityAccessException::for(
                $entity::class,
                $entity->getEntityId(),
                $this->context->accessibleIds(),
            );
        }
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof EntityScoped) {
            return;
        }

        if (!$this->context->canAccess($entity->getEntityId())) {
            throw CrossEntityAccessException::for(
                $entity::class,
                $entity->getEntityId(),
                $this->context->accessibleIds(),
            );
        }
    }
}
