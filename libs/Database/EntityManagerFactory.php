<?php

declare(strict_types=1);

namespace Liminal\Lib\Database;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Liminal\Lib\Database\Exception\DatabaseException;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Database\Scope\CompanyScopeFilter;
use Liminal\Lib\Database\Scope\CompanyScopeListener;
use Liminal\Registry\EntityRegistry;

/**
 * Assembles the EntityManager from what the modules contributed.
 *
 * Mapping is decentralised: each lib and module registers its own entity
 * namespace, and the chain is built from EntityRegistry::all(), which hands them
 * back ordered from most to least specific. That ordering is not decorative —
 * MappingDriverChain returns the first driver whose namespace prefixes the class
 * name, so a shorter namespace registered first would capture a longer one's
 * entities.
 */
final readonly class EntityManagerFactory
{
    public function __construct(
        private EntityRegistry $entities,
        private CompanyContext $context,
    ) {}

    /**
     * @throws DatabaseException when no entity namespace was contributed
     */
    public function create(string $dsn): EntityManagerInterface
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl($this->mappingChain());

        // PHP 8.4 lazy objects remove the generated-proxy directory entirely.
        // ORM 4.0 drops the alternative, so this is the forward-compatible path.
        $config->enableNativeLazyObjects(true);

        $config->addFilter(CompanyScopeFilter::NAME, CompanyScopeFilter::class);

        $listener = new CompanyScopeListener($this->context);
        $events = new EventManager();
        $events->addEventListener([Events::prePersist, Events::postLoad, Events::onFlush], $listener);

        $entityManager = new EntityManager(
            DriverManager::getConnection(Dsn::parse($dsn)),
            $config,
            $events,
        );

        $this->applyScope($entityManager);

        // Switching company must evict everything hydrated under the previous
        // scope: SQL filters do not retroactively apply to managed entities.
        $this->context->onSwitch(function () use ($entityManager): void {
            $entityManager->clear();
            $this->applyScope($entityManager);
        });

        return $entityManager;
    }

    private function applyScope(EntityManagerInterface $entityManager): void
    {
        $filters = $entityManager->getFilters();

        if (!$filters->isEnabled(CompanyScopeFilter::NAME)) {
            $filters->enable(CompanyScopeFilter::NAME);
        }

        $filters->getFilter(CompanyScopeFilter::NAME)
            ->setParameterList(CompanyScopeFilter::PARAMETER, $this->context->accessibleIds(), Types::INTEGER);
    }

    private function mappingChain(): MappingDriverChain
    {
        $chain = new MappingDriverChain();
        $namespaces = $this->entities->all();

        if ($namespaces === []) {
            throw DatabaseException::noEntityNamespaces();
        }

        foreach ($namespaces as $namespace => $paths) {
            // Do not pass the second argument: ORM 3.6 throws when it is false.
            $chain->addDriver(new AttributeDriver($paths), $namespace);
        }

        return $chain;
    }
}
