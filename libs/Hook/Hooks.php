<?php

declare(strict_types=1);

namespace Liminal\Lib\Hook;

use Closure;
use Liminal\Lib\Hook\Contract\HookListener;
use Liminal\Lib\Hook\Exception\HookException;
use Liminal\Registry\HookRegistry;

/**
 * The hook dispatcher: synchronous, in the flow, the value travels listener
 * to listener in priority order, and EXCEPTIONS PROPAGATE — a hook is
 * business logic, and its caller owns the failure.
 *
 * Listeners are resolved lazily per dispatch through a closure over the
 * container (the DeferredConnection pattern): registering a hundred
 * listeners costs nothing until one of their hooks actually runs, and the
 * console can hold this service without resolving anything.
 *
 * The returned value is mixed by construction — the DECLARING dispatch site
 * documents and validates the shape it expects back.
 */
final readonly class Hooks
{
    /**
     * @param Closure(string): object $resolve
     */
    public function __construct(
        private HookRegistry $registry,
        private Closure $resolve,
    ) {}

    /**
     * @param array<string, mixed> $parameters
     *
     * @throws HookException when the hook was never declared, or a listener is miswired
     */
    public function filter(string $hook, mixed $value, array $parameters = []): mixed
    {
        if (!$this->registry->has($hook)) {
            throw HookException::undeclaredHook($hook);
        }

        $context = new HookContext($hook, $parameters);

        foreach ($this->registry->listenersFor($hook) as $id) {
            $listener = ($this->resolve)($id);

            if (!$listener instanceof HookListener) {
                throw HookException::notAHookListener($id);
            }

            $value = $listener->transform($value, $context);
        }

        return $value;
    }
}
