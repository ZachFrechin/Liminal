<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Pdf;

use FilesystemIterator;
use Liminal\Lib\Pdf\Exception\PdfException;
use Liminal\Lib\Pdf\PdfRenderer;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The renderer over a bare Twig (ArrayLoader — no repo machinery) and a
 * temp cache: constructor injection makes dompdf's write location a test
 * concern, never the repo tree.
 */
#[CoversClass(PdfRenderer::class)]
final class PdfRendererTest extends TestCase
{
    private string $cache;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/liminal-pdf-' . bin2hex(random_bytes(6));
        mkdir($this->cache, 0o775, true);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cache, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($this->cache);
    }

    public function testBytesProduceAPdfAndTheFontDirTravels(): void
    {
        $renderer = $this->renderer([
            'doc.html.twig' => '<html><body><p>Hello {{ who }} from {{ _pdf_font_dir }}</p></body></html>',
        ]);

        $bytes = $renderer->bytes('doc.html.twig', ['who' => 'Ada']);

        self::assertStringStartsWith('%PDF', $bytes);
        self::assertGreaterThan(500, strlen($bytes));
    }

    public function testRespondCarriesTheDocumentHeaders(): void
    {
        $renderer = $this->renderer([
            'doc.html.twig' => '<html><body>ok</body></html>',
        ]);

        $response = $renderer->respond('doc.html.twig', [], 'INV-2026-0001.pdf');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertSame('inline; filename="INV-2026-0001.pdf"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringStartsWith('%PDF', (string) $response->getBody());
    }

    public function testABrokenTemplateThrowsTheDedicatedException(): void
    {
        $renderer = $this->renderer([
            'doc.html.twig' => '{{ missing_variable }}',
        ]);

        $this->expectException(PdfException::class);

        $renderer->bytes('doc.html.twig', []);
    }

    /**
     * @param array<string, string> $templates
     */
    private function renderer(array $templates): PdfRenderer
    {
        $twig = new Environment(new ArrayLoader($templates), ['strict_variables' => true]);

        return new PdfRenderer(
            $twig,
            new Psr17Factory(),
            dirname(__DIR__, 3) . '/libs/Pdf/fonts',
            $this->cache,
        );
    }
}
