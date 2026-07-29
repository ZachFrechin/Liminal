<?php

declare(strict_types=1);

namespace Liminal\Config;

final readonly class ConfigurationLoader
{
    public function __construct(private string $configDir)
    {
    }

    public function load(string ...$files): Configuration
    {
        $values = [];

        foreach ($files as $file) {
            $path = sprintf('%s/%s.php', $this->configDir, $file);

            if (!is_file($path)) {
                continue;
            }

            /** @var mixed $loaded */
            $loaded = require $path;

            if (is_array($loaded)) {
                /** @var array<string, mixed> $loaded */
                $values[$file] = $loaded;
            }
        }

        return new Configuration($values);
    }
}
