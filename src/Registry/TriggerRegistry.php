<?php

declare(strict_types=1);

namespace Liminal\Registry;

use InvalidArgumentException;
use Liminal\Registry\Exception\DuplicateContributionException;
use Liminal\Registry\Exception\UndeclaredListenerTargetException;

/**
 * The trigger side of the module-communication primitive: after the fact,
 * post commit, no modification, listener failures caught and logged.
 *
 * Same declare/listen split as hooks — the firing contributor declares the
 * SCREAMING_SNAKE name, consumers subscribe by service id — plus one thing
 * hooks deliberately lack: listenToAll(), the catch-all subscription the
 * audit trail rides. A module added next year fires triggers that are
 * audited with zero wiring, because audit-by-default is the only security
 * posture that survives forgetfulness.
 *
 * Merge rule, pinned: LOWER priority runs EARLIER; at equal priority,
 * name-specific listeners precede catch-alls, each group in registration
 * order (the concat feeds a stable usort).
 */
final class TriggerRegistry extends AbstractRegistry
{
    /**
     * SCREAMING_SNAKE, at most 64 characters — the width of the audit
     * table's event column: a name that cannot be stored must fail the
     * boot, not the insert.
     */
    private const string GRAMMAR = '/^[A-Z][A-Z0-9_]*$/';

    private const int MAX_LENGTH = 64;

    /** @var array<string, true> */
    private array $names = [];

    /** @var array<string, list<array{listener: string, priority: int}>> */
    private array $listeners = [];

    /** @var list<array{listener: string, priority: int}> */
    private array $catchAll = [];

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

    /**
     * Subscribes to every trigger, declared by anyone, forever.
     *
     * @throws DuplicateContributionException when this listener already subscribed to everything
     */
    public function listenToAll(string $listener, int $priority = 100): void
    {
        $this->assertMutable();

        $pair = '* => ' . $listener;

        if (isset($this->pairs[$pair])) {
            throw DuplicateContributionException::for(static::class, $pair);
        }

        $this->pairs[$pair] = true;
        $this->catchAll[] = ['listener' => $listener, 'priority' => $priority];
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
     * Listener service ids in dispatch order — specifics concatenated before
     * catch-alls, then stable-sorted by priority, so equal priorities keep
     * the pinned rule. Sorted on every read: tiny lists, and unit tests get
     * the real order without freezing.
     *
     * @return list<string>
     */
    public function listenersFor(string $name): array
    {
        $entries = [...$this->listeners[$name] ?? [], ...$this->catchAll];

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
        if (preg_match(self::GRAMMAR, $name) !== 1 || strlen($name) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Trigger name "%s" is invalid: expected SCREAMING_SNAKE of at most %d characters, like "INVOICE_VALIDATED".',
                $name,
                self::MAX_LENGTH,
            ));
        }
    }
}
