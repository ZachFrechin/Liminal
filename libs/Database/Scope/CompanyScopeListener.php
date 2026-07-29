<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Scope;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Liminal\Lib\Database\Exception\CompanyReassignmentException;
use Liminal\Lib\Database\Exception\CrossCompanyAccessException;

/**
 * Assigns and polices company_id around the ORM lifecycle.
 *
 * prePersist stamps new scoped entities with the current company through the
 * class metadata — the interface exposes no setter — so no caller has to
 * remember to. postLoad re-checks what actually came back: the defence in depth
 * the SQL filter cannot provide on its own, because a filter only shapes
 * generated SQL and says nothing about rows reaching the application some other
 * way (a disabled filter, a native query, a relation loaded before the scope
 * was applied).
 *
 * onFlush is the authoritative write gate. It fires after change sets are
 * computed and BEFORE the transaction opens, so throwing there aborts the flush
 * with zero SQL executed and leaves the EntityManager open. It re-validates
 * scheduled insertions (closing the persist-then-mutate window) and refuses ANY
 * company_id change on a managed entity — even towards an accessible company,
 * because a silent flip is indistinguishable from an exfiltration.
 *
 * What this does NOT cover: find() returning an entity already in the identity
 * map fires no postLoad, since the entity was hydrated on an earlier load. That
 * case is closed at the other end — CompanyContext::switchTo() clears the
 * EntityManager, so nothing hydrated under a previous scope survives into the
 * next one. Neither mechanism is sufficient alone; phase 3 adds voters on top.
 */
final readonly class CompanyScopeListener
{
    public function __construct(private CompanyContext $context) {}

    /**
     * @throws CrossCompanyAccessException when an explicit target company is out of scope
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof CompanyScoped) {
            return;
        }

        $companyId = $entity->getCompanyId();

        if ($companyId === null) {
            $metadata = $args->getObjectManager()->getClassMetadata($entity::class);
            $metadata->setFieldValue(
                $entity,
                $metadata->getFieldForColumn(CompanyScoped::COLUMN),
                $this->context->currentId(),
            );

            return;
        }

        // An explicit id is honoured only if the actor may actually reach it.
        if (!$this->context->canAccess($companyId)) {
            throw CrossCompanyAccessException::for($entity::class, $companyId, $this->context->accessibleIds());
        }
    }

    /**
     * @throws CrossCompanyAccessException when a foreign row reaches hydration anyway
     */
    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof CompanyScoped) {
            return;
        }

        if (!$this->context->canAccess($entity->getCompanyId())) {
            throw CrossCompanyAccessException::for(
                $entity::class,
                $entity->getCompanyId(),
                $this->context->accessibleIds(),
            );
        }
    }

    /**
     * @throws CrossCompanyAccessException  when a scheduled insertion targets an unreachable company
     * @throws CompanyReassignmentException when a managed entity changed company
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        // Re-validate inserts at flush time: prePersist ran at persist() and the
        // entity may have been mutated since.
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof CompanyScoped && !$this->context->canAccess($entity->getCompanyId())) {
                throw CrossCompanyAccessException::for(
                    $entity::class,
                    $entity->getCompanyId(),
                    $this->context->accessibleIds(),
                );
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof CompanyScoped) {
                continue;
            }

            $field = $entityManager->getClassMetadata($entity::class)->getFieldForColumn(CompanyScoped::COLUMN);
            $changeSet = $unitOfWork->getEntityChangeSet($entity);

            if (!array_key_exists($field, $changeSet)) {
                continue;
            }

            $change = $changeSet[$field];

            throw CompanyReassignmentException::for(
                $entity::class,
                is_array($change) ? ($change[0] ?? null) : null,
                is_array($change) ? ($change[1] ?? null) : null,
            );
        }
    }
}
