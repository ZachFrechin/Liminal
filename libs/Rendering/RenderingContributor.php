<?php

declare(strict_types=1);

namespace Liminal\Lib\Rendering;

use Liminal\Config\Configuration;
use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\Contract\DefinitionProvider;
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
    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(TemplateRegistry::class)
            ->add('liminal', __DIR__ . '/templates');
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
        ];
    }
}
