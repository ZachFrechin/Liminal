<?php

declare(strict_types=1);

namespace Liminal\Lib\Rendering\View;

use Liminal\Lib\Security\Session\Session;

/**
 * The request's session and path, made reachable from Twig functions — which
 * are container singletons and cannot read request attributes.
 *
 * Shared and mutable by design, the CurrentUser precedent: the
 * ViewContextMiddleware assigns BOTH unconditionally once per request.
 *
 * The path, not the matched route, is what marks the active menu item. Two
 * reasons, both load-bearing: the router THROWS on a 404, so anything read
 * after it would leave a previous request's route in the holder of a
 * worker-mode runtime — the exact leak this class exists to prevent; and a
 * MenuItem names one route while a section spans many (/users/12 must light
 * "Users"), so a route name could never answer "am I under this section"
 * without every item declaring the routes it owns.
 */
final class ViewContext
{
    private ?Session $session = null;

    private ?string $path = null;

    public function set(?Session $session): void
    {
        $this->session = $session;
    }

    public function get(): ?Session
    {
        return $this->session;
    }

    public function setPath(?string $path): void
    {
        $this->path = $path;
    }

    public function path(): ?string
    {
        return $this->path;
    }
}
