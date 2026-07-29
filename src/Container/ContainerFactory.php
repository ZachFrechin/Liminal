<?php

declare(strict_types=1);

namespace Liminal\Container;

use DI\ContainerBuilder;
use Liminal\Config\Configuration;
use Psr\Container\ContainerInterface;

/**
 * Builds the PSR-11 container.
 *
 * Compilation is wired from the start — driven by config rather than added later —
 * because module activation in phase 2 must be able to invalidate and rebuild the
 * compiled container as part of its lifecycle.
 */
final readonly class ContainerFactory
{
    public function __construct(private Configuration $config)
    {
    }

    /**
     * @param array<string, mixed> $definitions
     */
    public function create(array $definitions): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->useAttributes(false);
        $builder->addDefinitions($definitions);

        if ($this->config->bool('app.compile')) {
            $builder->enableCompilation($this->config->string('app.cache_dir') . '/container');
            $builder->writeProxiesToFile(true, $this->config->string('app.cache_dir') . '/proxies');
        }

        return $builder->build();
    }
}
