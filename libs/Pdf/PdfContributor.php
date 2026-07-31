<?php

declare(strict_types=1);

namespace Liminal\Lib\Pdf;

use Liminal\Config\Configuration;
use Liminal\Lib\Pdf\Exception\PdfException;
use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\TemplateRegistry;
use Psr\Http\Message\ResponseFactoryInterface;
use Twig\Environment;

/**
 * lib/pdf: documents as bytes. The lib owns the base document skeleton
 * (the @pdf namespace) and the renderer; the document TEMPLATES and their
 * routes belong to the modules, the api-lib precedent exactly — a pdf
 * route named invoice.pdf disables with the invoice module.
 */
final readonly class PdfContributor implements Contributor, DefinitionProvider
{
    public function contribute(RegistryCollection $registries): void
    {
        $registries->get(TemplateRegistry::class)
            ->add('pdf', __DIR__ . '/templates');
    }

    /**
     * @return array<string, mixed>
     */
    public function definitions(Configuration $config): array
    {
        return [
            // app.cache_dir is a kernel key the rendering lib already reads
            // for the twig cache — same contract here: dompdf writes its
            // font-metrics caches there, the vendored TTFs stay read-only.
            PdfRenderer::class => static function (
                Environment $twig,
                ResponseFactoryInterface $responses,
            ) use ($config): PdfRenderer {
                $cacheDir = $config->string('app.cache_dir') . '/dompdf';

                if (!is_dir($cacheDir) && !mkdir($cacheDir, 0o775, true) && !is_dir($cacheDir)) {
                    throw PdfException::cacheDirectoryNotWritable($cacheDir);
                }

                return new PdfRenderer($twig, $responses, __DIR__ . '/fonts', $cacheDir);
            },
        ];
    }
}
