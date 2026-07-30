<?php

declare(strict_types=1);

namespace Liminal\Lib\Module\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\Middleware\RouterMiddleware;
use Liminal\Http\RouteMatch;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Module\ModuleManager;
use Liminal\Registry\ModuleRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Closes the enablement loop: a module's routes only answer for companies the
 * module is enabled for.
 *
 * The route name's prefix is the key — the convention the Module contract
 * announces and the boot enforces. Library routes (system.*) pass untouched:
 * their prefix is not a declared module. Unnamed routes cannot belong to a
 * module, since the boot refuses those.
 *
 * A disabled module answers 404, byte-identical to a route that does not
 * exist: a 403 would confirm the module is installed but off, which is free
 * reconnaissance in a multi-tenant ERP. Known and accepted asymmetry: because
 * gating needs the company, which needs authentication, an anonymous hit on a
 * PROTECTED route of a disabled module gets 401 first — the hide-existence
 * property holds fully for public routes and authenticated users.
 */
final readonly class ModuleGateMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ModuleRegistry $modules,
        private ModuleManager $manager,
        private CompanyContext $context,
    ) {}

    /**
     * @throws HttpException as notFound() when the route's module is disabled here
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $match = $request->getAttribute(RouterMiddleware::ATTRIBUTE);

        if (!$match instanceof RouteMatch || $match->route === null) {
            return $handler->handle($request);
        }

        if ($match->route->public) {
            // A public route is pre-authentication, therefore pre-company: the
            // only company available here is the bootstrap default, so gating
            // by enablement would gate every visitor against company 1
            // regardless of who they are. It would also resolve a Connection to
            // answer — breaking public pages on a DSN-less checkout — and would
            // let `module:disable authentication` lock every user out of the
            // login page with no way back in. A module wanting a gated public
            // page gates it in its handler.
            return $handler->handle($request);
        }

        $module = $this->moduleOf($match->route->name);

        if ($module !== null && !$this->manager->isEnabled($module, $this->context->currentId())) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        return $handler->handle($request);
    }

    /**
     * @return string|null the declared module owning this route name, if any
     */
    private function moduleOf(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }

        $separator = strpos($routeName, '.');

        if ($separator === false) {
            return null;
        }

        $prefix = substr($routeName, 0, $separator);

        return $this->modules->has($prefix) ? $prefix : null;
    }
}
