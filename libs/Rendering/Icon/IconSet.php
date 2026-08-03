<?php

declare(strict_types=1);

namespace Liminal\Lib\Rendering\Icon;

use Liminal\Lib\Rendering\Exception\RenderingException;

/**
 * The vendored icon set — Lucide glyphs (lucide-static 0.441.0, ISC license,
 * https://lucide.dev) inlined as inner SVG markup, so a self-hosted install
 * renders icons without ever calling a CDN. The wrapping <svg class="lucide">
 * inherits stroke, color and width from the base stylesheet: a glyph recolors
 * with the text around it.
 *
 * The set grows one consumed glyph at a time — vendoring vocabulary nothing
 * renders is dead weight. The emitted markup carries no trailing whitespace:
 * menu labels are pinned as ">Label</a>" and an icon sits right before them.
 */
final readonly class IconSet
{
    private const array MAP = [
        'info' => '<circle cx="12" cy="12" r="10"/> <path d="M12 16v-4"/> <path d="M12 8h.01"/>',
        'circle-check-big' => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/> <path d="m9 11 3 3L22 4"/>',
        'triangle-alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/> <path d="M12 9v4"/> <path d="M12 17h.01"/>',
        'octagon-alert' => '<path d="M12 16h.01"/> <path d="M12 8v4"/> <path d="M15.312 2a2 2 0 0 1 1.414.586l4.688 4.688A2 2 0 0 1 22 8.688v6.624a2 2 0 0 1-.586 1.414l-4.688 4.688a2 2 0 0 1-1.414.586H8.688a2 2 0 0 1-1.414-.586l-4.688-4.688A2 2 0 0 1 2 15.312V8.688a2 2 0 0 1 .586-1.414l4.688-4.688A2 2 0 0 1 8.688 2z"/>',
        'x' => '<path d="M18 6 6 18"/> <path d="m6 6 12 12"/>',
        'inbox' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/> <path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'chevrons-up-down' => '<path d="m7 15 5 5 5-5"/> <path d="m7 9 5-5 5 5"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'arrow-up' => '<path d="m5 12 7-7 7 7"/> <path d="M12 19V5"/>',
        'arrow-down' => '<path d="M12 5v14"/> <path d="m19 12-7 7-7-7"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/> <polyline points="16 17 21 12 16 7"/> <line x1="21" x2="9" y1="12" y2="12"/>',
        'user-round' => '<circle cx="12" cy="8" r="5"/> <path d="M20 21a8 8 0 0 0-16 0"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/> <circle cx="9" cy="7" r="4"/> <path d="M22 21v-2a4 4 0 0 0-3-3.87"/> <path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/> <path d="m9 12 2 2 4-4"/>',
        'building-2' => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/> <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/> <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/> <path d="M10 6h4"/> <path d="M10 10h4"/> <path d="M10 14h4"/> <path d="M10 18h4"/>',
        'contact-round' => '<path d="M16 2v2"/> <path d="M17.915 22a6 6 0 0 0-12 0"/> <path d="M8 2v2"/> <circle cx="12" cy="12" r="4"/> <rect x="3" y="4" width="18" height="18" rx="2"/>',
        'receipt-text' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/> <path d="M14 8H8"/> <path d="M16 12H8"/> <path d="M13 16H8"/>',
        'shopping-cart' => '<circle cx="8" cy="21" r="1"/> <circle cx="19" cy="21" r="1"/> <path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
    ];

    /**
     * @throws RenderingException when no glyph carries this name
     */
    public function svg(string $name, int $size = 16): string
    {
        $inner = self::MAP[$name] ?? throw RenderingException::unknownIcon($name);

        // aria-hidden: icons are decorative by default — the adjacent label
        // carries the meaning. A future standalone use adds its own label.
        return sprintf(
            '<svg class="lucide" width="%d" height="%d" viewBox="0 0 24 24" aria-hidden="true">%s</svg>',
            $size,
            $size,
            $inner,
        );
    }
}
