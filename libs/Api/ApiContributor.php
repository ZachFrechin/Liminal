<?php

declare(strict_types=1);

namespace Liminal\Lib\Api;

use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\RegistryCollection;

/**
 * The api lib's manifest. Deliberately thin: the authentication machinery is
 * the security lib's (bearer credentials are credentials), the routes are
 * the modules' (a route named thirdparty.api.list gates as thirdparty — the
 * lib routes nothing, so no module named "api" can ever retroactively gate a
 * lib route), and the error envelope is the kernel's ErrorHandlerMiddleware,
 * which was JSON-first from day one. What this lib owns is the SHAPE of a
 * successful answer: the ApiResponder and its {data} / {data, meta}
 * envelopes, one convention every module's api surface shares.
 */
final readonly class ApiContributor implements Contributor
{
    public function contribute(RegistryCollection $registries): void
    {
        // Nothing to register: the responder is autowired, the conventions
        // are code. The manifest exists so the lib is declared, ordered and
        // visible like every other capability.
    }
}
