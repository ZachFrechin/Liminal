<?php

declare(strict_types=1);

namespace Liminal\Lib\Rendering;

use Liminal\Lib\Rendering\Twig\LiminalExtension;
use Liminal\Registry\TemplateRegistry;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Assembles the Twig environment from what the registries contributed.
 *
 * The TemplateRegistry serves each namespace's paths in Twig lookup order
 * (highest precedence first), so this factory feeds addPath() verbatim.
 * FilesystemLoader validates directories eagerly — a mistyped template path
 * fails at the first environment build, not at the first render of one page.
 */
final readonly class TwigFactory
{
    public function __construct(
        private TemplateRegistry $templates,
        private LiminalExtension $extension,
    ) {}

    /**
     * @param string|false $cache compiled-template directory, or false in debug
     */
    public function create(string|false $cache, bool $debug): Environment
    {
        $loader = new FilesystemLoader();

        foreach ($this->templates->all() as $namespace => $paths) {
            foreach ($paths as $path) {
                $loader->addPath($path, $namespace);
            }
        }

        $environment = new Environment($loader, [
            'cache' => $cache,
            'debug' => $debug,
            // A missing variable is a wiring bug, never a blank cell.
            'strict_variables' => true,
            // The Twig 3 default, pinned explicitly: norms live in code.
            'autoescape' => 'html',
            // Fresh adoption: no echo-era extensions to accommodate.
            'use_yield' => true,
        ]);

        $environment->addExtension($this->extension);

        return $environment;
    }
}
