<?php

declare(strict_types=1);

namespace Liminal\Lib\Rendering\View;

use Liminal\Lib\Security\Session\Session;

/**
 * The request's session, made reachable from Twig functions — which are
 * container singletons and cannot read request attributes.
 *
 * Shared and mutable by design, the CurrentUser precedent: the
 * ViewContextMiddleware assigns it unconditionally once per request. It holds
 * the Session only — CurrentUser and CompanyContext are already holders of
 * their own, and the matched Route (active menu item) is deliberately
 * deferred until something needs it.
 */
final class ViewContext
{
    private ?Session $session = null;

    public function set(?Session $session): void
    {
        $this->session = $session;
    }

    public function get(): ?Session
    {
        return $this->session;
    }
}
