<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authentication;

use Liminal\Lib\Security\Contract\LoginThrottle;

/**
 * The default until a module brings storage: every attempt proceeds.
 *
 * Unlike the other null defaults this one is NOT fail-closed, and that is the
 * only honest choice — refusing every login by default would make a
 * fresh installation unusable. The security posture is restored the moment the
 * authentication module binds its own implementation.
 */
final readonly class NullLoginThrottle implements LoginThrottle
{
    public function check(string $identifier, ClientContext $client): ?int
    {
        return null;
    }

    public function recordFailure(string $identifier, ClientContext $client): void {}

    public function recordSuccess(string $identifier, ClientContext $client): void {}
}
