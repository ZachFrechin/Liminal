<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authorization;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Security\Exception\UndeclaredPermissionException;

/**
 * The throwing companion to the Gate, for HTTP handlers.
 *
 * A separate class rather than a Gate method: baking the HTTP failure into the
 * Gate would make it unusable from the console, and console code simply never
 * injects this one. A lib rather than a module helper: every business module
 * needs it, and a helper living inside one module would make every other module
 * depend on that module — an edge app.modules cannot even express.
 */
final readonly class RequestGate
{
    public function __construct(private Gate $gate) {}

    /**
     * @throws UndeclaredPermissionException when the code was never contributed
     * @throws HttpException                 as forbidden() when the current user lacks it
     */
    public function authorize(string $permission): void
    {
        if (!$this->gate->allows($permission)) {
            throw HttpException::forbidden();
        }
    }
}
