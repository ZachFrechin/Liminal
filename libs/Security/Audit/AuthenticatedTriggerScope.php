<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Audit;

use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Hook\Contract\TriggerScope;
use Liminal\Lib\Security\Authentication\CurrentUser;

/**
 * The real answer to "who fired this, where": the authenticated user the
 * middleware assigned, the working company the switch middleware applied.
 * Overrides the hook lib's inert default through ordinary last-wins
 * layering — the sanctioned Security→Hook edge, pointing one way.
 *
 * In a console process both holders sit at their initial state: actor null,
 * company = bootstrap — which is why console fire points that know better
 * pass their company explicitly.
 */
final readonly class AuthenticatedTriggerScope implements TriggerScope
{
    public function __construct(
        private CurrentUser $currentUser,
        private CompanyContext $context,
    ) {}

    public function actorId(): ?int
    {
        return $this->currentUser->get()?->id();
    }

    public function companyId(): int
    {
        return $this->context->currentId();
    }
}
