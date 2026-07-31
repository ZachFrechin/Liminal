<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Rendering;

use Liminal\Lib\Rendering\Exception\RenderingException;
use Liminal\Lib\Rendering\Icon\IconSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IconSet::class)]
final class IconSetTest extends TestCase
{
    public function testAGlyphRendersAsAnInlineSvgAtTheRequestedSize(): void
    {
        $svg = new IconSet()->svg('check', 14);

        self::assertStringStartsWith('<svg class="lucide" width="14" height="14" viewBox="0 0 24 24" aria-hidden="true">', $svg);
        self::assertStringContainsString('<path d="M20 6 9 17l-5-5"/>', $svg);
        // The menu pins '>Label</a>' with the icon right before the label:
        // the markup must end exactly at the closing tag, no trailing space.
        self::assertStringEndsWith('</svg>', $svg);
    }

    public function testTheInvoiceGlyphIsVendored(): void
    {
        // The invoice module's menu entry names it; an absent glyph would
        // throw at every menu render for anyone holding invoice.read.
        self::assertStringContainsString('<path d="M14 8H8"/>', new IconSet()->svg('receipt-text'));
    }

    public function testTheOrderGlyphIsVendored(): void
    {
        self::assertStringContainsString('<circle cx="8" cy="21" r="1"/>', new IconSet()->svg('shopping-cart'));
    }

    public function testAnUnknownNameIsWiringAndThrows(): void
    {
        $this->expectException(RenderingException::class);
        $this->expectExceptionMessage('No icon named "sparkles"');

        new IconSet()->svg('sparkles');
    }
}
