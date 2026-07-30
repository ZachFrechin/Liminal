<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The token contract, machine-checked: every custom property a component
 * stylesheet consumes must be defined by the token layer (or by the
 * component itself — private variables and dark-theme overrides count).
 * CoversNothing: this test reads files, not classes — the drift it guards
 * is between stylesheets no PHP symbol represents.
 */
#[CoversNothing]
final class TokenDriftTest extends TestCase
{
    public function testEveryConsumedVariableIsDefined(): void
    {
        $root = dirname(__DIR__, 3) . '/public/assets/css';

        $defined = [];
        foreach ([...glob($root . '/tokens/*.css') ?: [], ...glob($root . '/components/*.css') ?: []] as $file) {
            preg_match_all('/(--[A-Za-z0-9_-]+)\s*:/', $this->stripped($file), $matches);
            $defined = [...$defined, ...$matches[1]];
        }
        $defined = array_flip($defined);

        self::assertNotEmpty($defined, 'No token definitions found — the css tree moved?');

        $undefined = [];
        foreach (glob($root . '/components/*.css') ?: [] as $file) {
            // The global match also catches nested fallbacks: var(--a, var(--b)).
            preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $this->stripped($file), $matches);
            foreach ($matches[1] as $variable) {
                if (!isset($defined[$variable])) {
                    $undefined[basename($file) . ': ' . $variable] = true;
                }
            }
        }

        self::assertSame([], array_keys($undefined), 'Component stylesheets consume undefined tokens.');
    }

    private function stripped(string $file): string
    {
        $css = file_get_contents($file);
        self::assertIsString($css);

        return preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
    }
}
