<?php

declare(strict_types=1);

namespace Liminal\Lib\Rendering;

use Liminal\Lib\Rendering\Exception\RenderingException;
use Liminal\Registry\TranslationRegistry;

/**
 * Resolves translation keys against the contributed catalogues.
 *
 * Two failure classes, deliberately split. A missing KEY returns the key
 * itself: translations are content, a raw "module.thing.title" on screen is
 * its own alarm, and no label is worth a 500. A malformed catalogue FILE is
 * wiring, and fails loud at first load.
 *
 * The locale comes from configuration, never from the settings service: that
 * one holds a real connection, and every page — including anonymous ones on a
 * DSN-less checkout — must render. A settings-backed override arrives with a
 * real locale switcher.
 */
final class Translator
{
    /** @var array<string, array<string, string>> locale => merged catalogue */
    private array $catalogues = [];

    public function __construct(
        private readonly TranslationRegistry $files,
        private readonly string $locale,
        private readonly string $fallbackLocale = 'en',
    ) {}

    /**
     * @param array<string, string|int|float> $parameters replaced as %name%
     *
     * @throws RenderingException when a catalogue file is missing or malformed
     */
    public function trans(string $key, array $parameters = []): string
    {
        $message = $this->catalogue($this->locale)[$key]
            ?? $this->catalogue($this->fallbackLocale)[$key]
            ?? $key;

        if ($parameters === []) {
            return $message;
        }

        $replacements = [];

        foreach ($parameters as $name => $value) {
            $replacements['%' . $name . '%'] = (string) $value;
        }

        // strtr, not sprintf: positional arguments break the moment a
        // language reorders the sentence.
        return strtr($message, $replacements);
    }

    /**
     * @return array<string, string>
     *
     * @throws RenderingException
     */
    private function catalogue(string $locale): array
    {
        if (array_key_exists($locale, $this->catalogues)) {
            return $this->catalogues[$locale];
        }

        $catalogue = [];

        foreach ($this->files->all()[$locale] ?? [] as $file) {
            // Contribution order is merge order: a module's file overrides a
            // lib's keys, mirroring the container's definition layering.
            $catalogue = array_replace($catalogue, $this->load($file));
        }

        return $this->catalogues[$locale] = $catalogue;
    }

    /**
     * @return array<string, string>
     *
     * @throws RenderingException when the file is absent or does not return a map of strings
     */
    private function load(string $file): array
    {
        if (!is_file($file)) {
            throw RenderingException::invalidCatalogue($file);
        }

        /** @var mixed $loaded */
        $loaded = require $file;

        if (!is_array($loaded)) {
            throw RenderingException::invalidCatalogue($file);
        }

        $catalogue = [];

        foreach ($loaded as $key => $message) {
            if (!is_string($key) || !is_string($message)) {
                throw RenderingException::invalidCatalogue($file);
            }

            $catalogue[$key] = $message;
        }

        return $catalogue;
    }
}
