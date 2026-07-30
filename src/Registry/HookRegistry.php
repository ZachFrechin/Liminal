<?php

declare(strict_types=1);

namespace Liminal\Registry;

use InvalidArgumentException;
use Liminal\Registry\Exception\DuplicateContributionException;
use Liminal\Registry\Exception\UndeclaredListenerTargetException;

/**
 * The hook side of the module-communication primitive: synchronous, in the
 * flow, the value travels listener to listener, exceptions propagate.
 *
 * The contributor that DISPATCHES a hook declares its name; consumers listen
 * by container service id (the CommandRegistry precedent: stored cold,
 * resolved at dispatch time). Names carry no imposed module prefix — nothing
 * gates by hook name, and a collision between two declarers breaks the boot
 * loudly, which IS the coordination mechanism.
 *
 * Priorities follow the house rule: LOWER runs EARLIER; ties keep
 * registration order (usort is stable).
 */
final class HookRegistry extends AbstractRegistry
{
    /** Three dotted segments at least: module.noun.verb, invoice.total.compute. */
    private const string GRAMMAR = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){2,}$/';

    /** @var array<string, true> */
    private array $names = [];

    /** @var array<string, list<array{listener: string, priority: int}>> */
    private array $listeners = [];

    /** @var array<string, true> */
    private array $pairs = [];

    /**
     * @throws InvalidArgumentException        when the name breaks the grammar
     * @throws DuplicateContributionException  when the name is already declared
     */
    public function declare(string $name): void
    {
        $this->assertMutable();
        $this->assertGrammar($name);

        if (isset($this->names[$name])) {
            throw DuplicateContributionException::for(static::class, $name);
        }

        $this->names[$name] = true;
    }

    /**
     * The target name is validated against the declarations at FREEZE, not
     * here: the declaring contributor may come later in boot order.
     *
     * @throws InvalidArgumentException        when the name breaks the grammar
     * @throws DuplicateContributionException  when this listener already subscribed to this name
     */
    public function listen(string $name, string $listener, int $priority = 100): void
    {
        $this->assertMutable();
        $this->assertGrammar($name);

        $pair = $name . ' => ' . $listener;

        if (isset($this->pairs[$pair])) {
            throw DuplicateContributionException::for(static::class, $pair);
        }

        $this->pairs[$pair] = true;
        $this->listeners[$name][] = ['listener' => $listener, 'priority' => $priority];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->names);
    }

    public function has(string $name): bool
    {
        return isset($this->names[$name]);
    }

    /**
     * Listener service ids in dispatch order. Sorted on every read — the
     * lists are tiny, and unit tests get the real order without freezing.
     *
     * @return list<string>
     */
    public function listenersFor(string $name): array
    {
        $entries = $this->listeners[$name] ?? [];

        usort($entries, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return array_column($entries, 'listener');
    }

    /**
     * @throws UndeclaredListenerTargetException when a subscription targets a name nobody declared
     */
    protected function onFreeze(): void
    {
        foreach ($this->listeners as $name => $entries) {
            if (!isset($this->names[$name])) {
                throw UndeclaredListenerTargetException::for(static::class, $name, $entries[0]['listener']);
            }
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertGrammar(string $name): void
    {
        if (preg_match(self::GRAMMAR, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Hook name "%s" is invalid: expected at least three dotted lowercase segments, like "invoice.total.compute".',
                $name,
            ));
        }
    }
}
