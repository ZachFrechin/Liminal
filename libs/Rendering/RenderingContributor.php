<?php

declare(strict_types=1);

namespace Liminal\Lib\Rendering;

use Liminal\Config\Configuration;
use Liminal\Lib\Rendering\Http\ViewContextMiddleware;
use Liminal\Lib\Rendering\View\ViewContext;
use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\MiddlewareRegistry;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\TemplateRegistry;
use Twig\Environment;

/**
 * lib/rendering: the presentation layer every page-serving module builds on —
 * Twig, the view helpers, the menu, translations and the HTML error pages.
 *
 * Rendering sits atop every other lib and nothing consumes it. Its sanctioned
 * lib-to-lib edges: Rendering → Security (session, current user, CSRF, gate),
 * Rendering → Module (registry and enablement for the menu), Rendering →
 * Database (company context).
 */
final class RenderingContributor implements Contributor, DefinitionProvider
{
    /**
     * Immediately inside the session middleware (−900): every HttpException
     * thrower downstream unwinds past an already-primed ViewContext, so the
     * HTML error page at −950 always renders THIS request's state.
     */
    public const int VIEW_CONTEXT_PRIORITY = -850;

    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(TemplateRegistry::class)
            ->add('liminal', __DIR__ . '/templates');

        $registries->get(MiddlewareRegistry::class)
            ->add(ViewContextMiddleware::class, self::VIEW_CONTEXT_PRIORITY);
    }

    /**
     * @return array<string, mixed>
     */
    public function definitions(Configuration $config): array
    {
        return [
            // Lazy: resolved at the first HTTP pipeline build, never in
            // console. Compiled templates cache under app.cache_dir — NEVER a
            // hardcoded path: fixture roots point cache_dir at the temp dir.
            Environment::class => static fn(TwigFactory $factory): Environment => $factory->create(
                $config->bool('app.debug') ? false : $config->string('app.cache_dir') . '/twig',
                $config->bool('app.debug'),
            ),

            // Shared and mutable by design — the CurrentUser precedent: the
            // ViewContextMiddleware assigns it once per request.
            ViewContext::class => static fn(): ViewContext => new ViewContext(),
        ];
    }
}
