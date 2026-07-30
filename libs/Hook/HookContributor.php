<?php

declare(strict_types=1);

namespace Liminal\Lib\Hook;

use Closure;
use Liminal\Config\Configuration;
use Liminal\Lib\Hook\Contract\TriggerScope;
use Liminal\Lib\Hook\Exception\HookException;
use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\HookRegistry;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\TriggerRegistry;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The hook lib's manifest. The registries are kernel-owned; this lib ships
 * the two dispatchers and the contracts listeners implement.
 *
 * Its only dependencies are the kernel and PSR: the enrichment holders live
 * behind the TriggerScope contract precisely so this lib never depends on
 * the libs that consume it (the security lib both implements TriggerListener
 * for the audit AND overrides TriggerScope — that edge points one way).
 */
final readonly class HookContributor implements Contributor, DefinitionProvider
{
    public function contribute(RegistryCollection $registries): void
    {
        // Nothing to contribute: declarations and subscriptions belong to
        // the contributors that fire and listen.
    }

    /**
     * @return array<string, mixed>
     */
    public function definitions(Configuration $config): array
    {
        return [
            Hooks::class => static fn(
                ContainerInterface $container,
                HookRegistry $registry,
            ): Hooks => new Hooks($registry, self::resolver($container)),

            Triggers::class => static fn(
                ContainerInterface $container,
                TriggerRegistry $registry,
                TriggerScope $scope,
                LoggerInterface $logger,
            ): Triggers => new Triggers($registry, self::resolver($container), $scope, $logger),

            // Inert by design: the security lib overrides this with the real
            // actor and working company through ordinary last-wins layering.
            TriggerScope::class => static fn(): TriggerScope => new NullTriggerScope(),
        ];
    }

    /**
     * Listener ids resolve lazily at dispatch time — the DeferredConnection
     * pattern, so holding a dispatcher never resolves a single listener.
     *
     * @return Closure(string): object
     */
    private static function resolver(ContainerInterface $container): Closure
    {
        return static function (string $id) use ($container): object {
            $service = $container->get($id);

            if (!is_object($service)) {
                throw HookException::notAService($id);
            }

            return $service;
        };
    }
}
