<?php

declare(strict_types=1);

namespace Liminal\Lib\Hook;

use Closure;
use DateTimeImmutable;
use Liminal\Lib\Hook\Contract\TriggerListener;
use Liminal\Lib\Hook\Contract\TriggerScope;
use Liminal\Lib\Hook\Exception\HookException;
use Liminal\Registry\TriggerRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The trigger dispatcher: after the fact, post commit, no modification.
 *
 * Every listener runs inside its own catch — INCLUDING the miswired-service
 * guard: a trigger fires after the deed is committed, and no listener bug,
 * wiring included, may break the response that carried the deed. Failures
 * go to the logger with the house 'exception' context key and the next
 * listener still runs.
 *
 * Firing an undeclared name, by contrast, throws: the fire point is code,
 * and a typo there is wiring on the DISPATCH side, where failing loud is
 * the only honest option.
 *
 * The logger is injected directly, not deferred: it is needed precisely
 * inside the catch, where a deferred resolver's own failure would replace
 * the listener's exception with a wiring one. Every constructor dependency
 * is console-safe — a command may hold this service on a DSN-less checkout.
 *
 * Post-commit is a CONVENTION the fire points must honor, not machinery:
 * a fire inside an open transaction would enroll listeners' writes in it.
 * The audit is therefore best-effort by construction — a future
 * audit-or-abort requirement would be a hook, not a trigger.
 */
final readonly class Triggers
{
    /**
     * @param Closure(string): object $resolve
     */
    public function __construct(
        private TriggerRegistry $registry,
        private Closure $resolve,
        private TriggerScope $scope,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, scalar|null> $payload identifying facts only — never a secret
     * @param ?int                       $companyId the company the event belongs to, when the
     *                                              fire point knows better than the working
     *                                              context (console commands pass --company)
     *
     * @throws HookException when the trigger was never declared
     */
    public function fire(string $name, array $payload = [], ?int $companyId = null): void
    {
        if (!$this->registry->has($name)) {
            throw HookException::undeclaredTrigger($name);
        }

        $event = new TriggerEvent(
            $name,
            $payload,
            $this->scope->actorId(),
            $companyId ?? $this->scope->companyId(),
            new DateTimeImmutable(),
        );

        foreach ($this->registry->listenersFor($name) as $id) {
            try {
                $listener = ($this->resolve)($id);

                if (!$listener instanceof TriggerListener) {
                    throw HookException::notATriggerListener($id);
                }

                $listener->react($event);
            } catch (Throwable $exception) {
                $this->logger->error('Trigger listener failed.', [
                    'exception' => $exception,
                    'trigger' => $name,
                    'listener' => $id,
                ]);
            }
        }
    }
}
