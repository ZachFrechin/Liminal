<?php

declare(strict_types=1);

namespace Liminal\Lib\Hook;

use Liminal\Lib\Hook\Contract\TriggerScope;

/**
 * The scope a lib can honestly claim on its own: nobody authenticated, the
 * first company. Real installations never see it — the security lib's
 * override wins the definition layering — but a checkout without that lib
 * still fires triggers with a defensible answer instead of a null crash.
 */
final readonly class NullTriggerScope implements TriggerScope
{
    public function actorId(): ?int
    {
        return null;
    }

    public function companyId(): int
    {
        return 1;
    }
}
