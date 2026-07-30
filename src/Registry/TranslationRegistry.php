<?php

declare(strict_types=1);

namespace Liminal\Registry;

use InvalidArgumentException;
use Liminal\Registry\Exception\DuplicateContributionException;

/**
 * Maps locales to translation files — PHP files returning key => string maps.
 *
 * Explicit file paths, never scanned directories: contributions are zero-I/O
 * manifests, and the Translator loads lazily at first use. Contribution order
 * is preserved so the merge is last-wins — a module's file overrides a lib's
 * keys, mirroring the container's definition layering.
 */
final class TranslationRegistry extends AbstractRegistry
{
    /** @var array<string, list<string>> */
    private array $locales = [];

    /**
     * @param non-empty-string $locale "en" or "fr_FR" — normalised spellings
     *                                 only, so catalogues cannot silently fork
     *
     * @throws InvalidArgumentException       when the locale is malformed
     * @throws DuplicateContributionException when a locale+file pair is contributed twice
     */
    public function add(string $locale, string $file): void
    {
        $this->assertMutable();

        if (preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', $locale) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Locale "%s" must be of the form "en" or "fr_FR".',
                $locale,
            ));
        }

        if (in_array($file, $this->locales[$locale] ?? [], true)) {
            throw DuplicateContributionException::for(static::class, $locale . ' => ' . $file);
        }

        $this->locales[$locale][] = $file;
    }

    /**
     * @return array<string, list<string>> locale => files in contribution order (later wins at merge)
     */
    public function all(): array
    {
        return $this->locales;
    }
}
