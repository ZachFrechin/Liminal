<?php

declare(strict_types=1);

namespace Liminal\Lib\Pdf\Exception;

use Liminal\Exception\LiminalException;
use RuntimeException;
use Throwable;

/**
 * A document failed to become bytes. RuntimeException, not Logic: the
 * template and the engine are wired correctly — the failure happened while
 * rendering real content at request time.
 */
final class PdfException extends RuntimeException implements LiminalException
{
    public static function renderFailed(string $template, Throwable $previous): self
    {
        return new self(
            sprintf('Rendering "%s" to PDF failed: %s', $template, $previous->getMessage()),
            previous: $previous,
        );
    }

    public static function cacheDirectoryNotWritable(string $path): self
    {
        return new self(sprintf('Cannot create the pdf cache directory "%s".', $path));
    }
}
