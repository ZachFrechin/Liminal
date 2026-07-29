<?php

declare(strict_types=1);

namespace Liminal\Config;

use Liminal\Config\Exception\MissingConfigurationException;

/**
 * Loads the named config/<file>.php files into one Configuration, each file
 * becoming a top-level key. Requested files are required files: a typo or a
 * missing deployment artefact fails the boot here, with the path in the
 * message, rather than as a puzzling missing-key error later.
 */
final readonly class ConfigurationLoader
{
    public function __construct(private string $configDir) {}

    /**
     * @throws MissingConfigurationException when a requested file is absent or does not return an array
     */
    public function load(string ...$files): Configuration
    {
        $values = [];

        foreach ($files as $file) {
            $path = sprintf('%s/%s.php', $this->configDir, $file);

            if (!is_file($path)) {
                throw MissingConfigurationException::file($path);
            }

            /** @var mixed $loaded */
            $loaded = require $path;

            if (!is_array($loaded)) {
                throw MissingConfigurationException::file($path);
            }

            /** @var array<string, mixed> $loaded */
            $values[$file] = $loaded;
        }

        return new Configuration($values);
    }
}
