<?php

declare(strict_types=1);

namespace Liminal\Lib\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Liminal\Lib\Pdf\Exception\PdfException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Twig in, PDF bytes out — the rendering lib's one sanctioned consumer:
 * the same Environment that serves the screens renders the documents.
 *
 * One Dompdf instance per render (upstream treats them as single-document).
 * The engine never touches the network (isRemoteEnabled stays false — the
 * zero-CDN promise holds for documents too) and only ever reads the
 * vendored fonts directory (chroot) while writing its font metrics caches
 * into the app cache — the vendored TTFs stay read-only, the same contract
 * as the twig cache.
 */
final readonly class PdfRenderer
{
    public function __construct(
        private \Twig\Environment $twig,
        private ResponseFactoryInterface $responseFactory,
        private string $fontsDir,
        private string $cacheDir,
    ) {}

    /**
     * @param array<string, mixed> $context
     *
     * @throws PdfException when the template or the engine refuses
     */
    public function bytes(string $template, array $context): string
    {
        try {
            $html = $this->twig->render($template, [
                ...$context,
                // The single door through which templates reach the vendored
                // fonts: @font-face src urls, resolved under the chroot.
                '_pdf_font_dir' => 'file://' . $this->fontsDir,
            ]);

            $options = new Options();
            $options->setChroot([$this->fontsDir]);
            $options->setFontDir($this->cacheDir);
            $options->setFontCache($this->cacheDir);
            $options->setIsRemoteEnabled(false);

            $dompdf = new Dompdf($options);
            $dompdf->setPaper('A4');
            $dompdf->loadHtml($html);
            $dompdf->render();

            return (string) $dompdf->output();
        } catch (Throwable $e) {
            throw PdfException::renderFailed($template, $e);
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @throws PdfException when the template or the engine refuses
     */
    public function respond(string $template, array $context, string $filename): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', sprintf('inline; filename="%s"', $filename))
            ->withHeader('Cache-Control', 'no-store');

        $response->getBody()->write($this->bytes($template, $context));

        return $response;
    }
}
